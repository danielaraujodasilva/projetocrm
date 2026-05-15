const {
  default: makeWASocket,
  useMultiFileAuthState,
  fetchLatestBaileysVersion,
  downloadMediaMessage,
  DisconnectReason
} = require("@whiskeysockets/baileys");

const axios = require("axios");
const express = require("express");
const fs = require("fs");
const path = require("path");
const pino = require("pino");
const QRCode = require("qrcode");

const app = express();
app.use(express.json({ limit: "30mb" }));

const serviceVersion = "2026-05-15-direct-start";
const port = Number(process.env.WHATSAPP_PORT || 3010);
const defaultWebhookUrl = process.env.WHATSAPP_WEBHOOK_URL || "http://localhost/projetocrm/api/whatsapp_webhook.php";
const sessionsDir = path.join(__dirname, "sessions");
const sessions = new Map();

function safeSessionKey(value) {
  const key = String(value || "").toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "");
  if (!key) throw new Error("Chave da sessao invalida");
  return key.slice(0, 120);
}

function jidToDigits(jid) {
  return String(jid || "").split("@")[0].replace(/\D/g, "");
}

function isPhoneJid(jid) {
  return String(jid || "").endsWith("@s.whatsapp.net");
}

function isLidJid(jid) {
  return String(jid || "").endsWith("@lid");
}

function getMessageContent(message) {
  return message?.ephemeralMessage?.message ||
    message?.viewOnceMessage?.message ||
    message?.viewOnceMessageV2?.message ||
    message;
}

function extractText(message) {
  const content = getMessageContent(message);
  return content?.conversation ||
    content?.extendedTextMessage?.text ||
    content?.imageMessage?.caption ||
    content?.videoMessage?.caption ||
    content?.documentMessage?.caption ||
    "";
}

function getMediaMessage(message) {
  const content = getMessageContent(message);
  const mediaTypes = [
    ["image", content?.imageMessage],
    ["video", content?.videoMessage],
    ["audio", content?.audioMessage],
    ["document", content?.documentMessage],
    ["sticker", content?.stickerMessage]
  ];
  for (const [type, payload] of mediaTypes) {
    if (payload) return { type, payload };
  }
  return null;
}

function toUnixTimestamp(value) {
  if (!value) return Math.floor(Date.now() / 1000);
  if (typeof value === "number") return value;
  if (typeof value === "string") return Number(value) || Math.floor(Date.now() / 1000);
  if (typeof value.toNumber === "function") return value.toNumber();
  if (typeof value.low === "number") return value.low;
  return Math.floor(Date.now() / 1000);
}

function normalizeBaileysStatus(status) {
  if (status === undefined || status === null) return "";
  if (typeof status === "number") return status;
  const map = {
    error: "error",
    pending: "pending",
    server_ack: "sent",
    delivery_ack: "delivered",
    read: "read",
    played: "played",
    0: "error",
    1: "pending",
    2: "sent",
    3: "delivered",
    4: "read",
    5: "played"
  };
  return map[String(status).toLowerCase()] || String(status);
}

function extractContactNumber(session, key) {
  const jid = key?.remoteJid || "";
  const phoneCandidates = [
    key?.remoteJidAlt,
    key?.senderPn,
    key?.participantAlt,
    key?.participant,
    isPhoneJid(jid) ? jid : ""
  ];

  for (const candidate of phoneCandidates) {
    if (isPhoneJid(candidate)) {
      const digits = jidToDigits(candidate);
      if (digits.length >= 10) return { numero: digits, source: "phone_jid" };
    }
  }

  const lidCandidates = [
    isLidJid(jid) ? jid : "",
    isLidJid(key?.senderLid) ? key.senderLid : "",
    isLidJid(key?.participant) ? key.participant : ""
  ];

  for (const candidate of lidCandidates) {
    const lid = jidToDigits(candidate);
    const mappedPhone = session.lidToPhone.get(lid);
    if (mappedPhone) return { numero: mappedPhone, source: "lid_map", lid };
    if (lid.length >= 6) return { numero: lid, source: "lid_fallback", lid };
  }

  return { numero: "", source: "not_found" };
}

function createSession(sessionKey) {
  const key = safeSessionKey(sessionKey);
  const existing = sessions.get(key);
  if (existing) return existing;

  const session = {
    key,
    status: "disconnected",
    sock: null,
    qr: "",
    qrImage: "",
    phone: "",
    lastError: "",
    webhookUrl: defaultWebhookUrl,
    webhookToken: "",
    studioId: null,
    studioSlug: "",
    studioName: "",
    startedAt: Math.floor(Date.now() / 1000),
    restartAttempts: 0,
    shouldReconnect: false,
    reconnectTimer: null,
    lidToPhone: new Map()
  };
  sessions.set(key, session);
  return session;
}

function sessionStartConfig(session) {
  return {
    webhookUrl: session.webhookUrl,
    webhookToken: session.webhookToken,
    studioId: session.studioId,
    studioSlug: session.studioSlug,
    studioName: session.studioName
  };
}

async function postWebhook(session, payload) {
  const body = {
    studioSessionKey: session.key,
    studioId: session.studioId,
    studioSlug: session.studioSlug,
    webhookToken: session.webhookToken,
    ...payload
  };
  try {
    const response = await axios.post(session.webhookUrl, body, { timeout: 15000 });
    return response.data;
  } catch (error) {
    console.error(`[${session.key}] Falha ao chamar webhook:`, error.message);
    return { ok: false, error: error.message };
  }
}

async function notifyStatus(session, status, message = "") {
  await postWebhook(session, { statusEvent: true, status, message });
}

async function startSession(sessionKey, config = {}) {
  const session = createSession(sessionKey);
  session.webhookUrl = config.webhookUrl || session.webhookUrl || defaultWebhookUrl;
  session.webhookToken = config.webhookToken || session.webhookToken || "";
  session.studioId = config.studioId || session.studioId || null;
  session.studioSlug = config.studioSlug || session.studioSlug || "";
  session.studioName = config.studioName || session.studioName || "";
  session.shouldReconnect = true;
  if (session.reconnectTimer) {
    clearTimeout(session.reconnectTimer);
    session.reconnectTimer = null;
  }

  if (session.sock && ["connected", "waiting_qr", "starting"].includes(session.status)) {
    return session;
  }

  session.status = "starting";
  session.lastError = "";
  session.startedAt = Math.floor(Date.now() / 1000);
  fs.mkdirSync(path.join(sessionsDir, session.key, "auth_info"), { recursive: true });

  const { state, saveCreds } = await useMultiFileAuthState(path.join(sessionsDir, session.key, "auth_info"));
  const { version } = await fetchLatestBaileysVersion();

  const sock = makeWASocket({
    version,
    auth: state,
    printQRInTerminal: false,
    logger: pino({ level: "silent" }),
    markOnlineOnConnect: true
  });
  session.sock = sock;

  sock.ev.on("creds.update", saveCreds);

  sock.ev.on("connection.update", async (update) => {
    const { connection, qr, lastDisconnect } = update;
    const disconnectCode = lastDisconnect?.error?.output?.statusCode;
    const disconnectMessage = lastDisconnect?.error?.message || "";

    console.log(`[${session.key}] connection.update`, {
      connection: connection || "",
      hasQr: !!qr,
      disconnectCode: disconnectCode || "",
      disconnectMessage
    });

    if (qr) {
      session.qr = qr;
      session.qrImage = await QRCode.toDataURL(qr, { margin: 1, scale: 5 });
      session.status = "waiting_qr";
      await notifyStatus(session, "waiting_qr", "QR Code gerado para vincular WhatsApp.");
    }

    if (connection === "open") {
      session.status = "connected";
      session.qr = "";
      session.qrImage = "";
      session.restartAttempts = 0;
      session.shouldReconnect = true;
      session.phone = jidToDigits(sock.user?.id || "");
      await notifyStatus(session, "connected", "WhatsApp conectado.");
      console.log(`[${session.key}] WhatsApp conectado ${session.phone || ""}`);
    }

    if (connection === "close") {
      const code = disconnectCode;
      const loggedOut = code === DisconnectReason.loggedOut;
      const shouldReconnect = session.shouldReconnect !== false && !loggedOut;
      if (session.sock === sock) {
        session.sock = null;
      }
      session.qr = "";
      session.qrImage = "";
      session.lastError = [disconnectMessage, code ? `codigo ${code}` : ""].filter(Boolean).join(" | ");

      if (shouldReconnect) {
        session.status = "starting";
        session.restartAttempts = (session.restartAttempts || 0) + 1;
        const delay = Math.min(1000 * session.restartAttempts, 5000);
        console.log(`[${session.key}] Conexao fechada; tentando reconectar em ${delay}ms`);
        notifyStatus(session, "starting", "Conexao fechada; tentando reconectar automaticamente.").catch(() => null);
        if (session.reconnectTimer) {
          clearTimeout(session.reconnectTimer);
        }
        const timer = setTimeout(() => {
          session.reconnectTimer = null;
          startSession(session.key, sessionStartConfig(session)).catch((error) => {
            session.status = "disconnected";
            session.lastError = error.message || "Falha ao reconectar.";
            console.error(`[${session.key}] Falha ao reconectar:`, session.lastError);
            notifyStatus(session, "disconnected", session.lastError).catch(() => null);
          });
        }, delay);
        timer.unref?.();
        session.reconnectTimer = timer;
        return;
      }

      session.status = "disconnected";
      await notifyStatus(session, session.status, loggedOut ? "Sessao encerrada." : "Conexao fechada; reconecte pelo painel se necessario.");
    }
  });

  sock.ev.on("messages.upsert", async ({ messages, type }) => {
    if (!["notify", "append"].includes(type)) return;

    for (const msg of messages) {
      if (!msg.message) continue;
      if (type === "append" && Number(msg.messageTimestamp || 0) < session.startedAt - 10) continue;

      const key = msg.key || {};
      const jid = key.remoteJid || "";
      if (!jid || jid.endsWith("@g.us") || jid.endsWith("@broadcast")) continue;

      const identity = extractContactNumber(session, key);
      if (!identity.numero) continue;
      if (jid.endsWith("@lid") && identity.source !== "lid_fallback") {
        session.lidToPhone.set(jidToDigits(jid), identity.numero);
      }

      const texto = extractText(msg.message);
      const mediaInfo = getMediaMessage(msg.message);
      const mediaPayload = {};

      if (mediaInfo) {
        try {
          const buffer = await downloadMediaMessage(
            msg,
            "buffer",
            {},
            { logger: pino({ level: "silent" }) }
          );
          mediaPayload.mediaBase64 = buffer.toString("base64");
          mediaPayload.mediaMime = mediaInfo.payload.mimetype || "";
          mediaPayload.mediaFileName = mediaInfo.payload.fileName || `${mediaInfo.type}_${key.id || Date.now()}`;
        } catch (error) {
          console.error(`[${session.key}] Erro ao baixar midia:`, error.message);
        }
      }

      if (!String(texto || "").trim() && !mediaInfo) continue;

      await postWebhook(session, {
        numero: identity.numero,
        mensagem: texto,
        fromMe: !!key.fromMe,
        messageId: key.id || null,
        remoteJid: key.remoteJid || jid,
        timestamp: toUnixTimestamp(msg.messageTimestamp),
        jidCompleto: jid,
        isLid: jid.endsWith("@lid"),
        tipoMensagem: mediaInfo?.type || "texto",
        ...mediaPayload
      });
    }
  });

  sock.ev.on("messages.update", async (updates) => {
    for (const item of updates) {
      const messageId = item.key?.id;
      const status = normalizeBaileysStatus(item.update?.status);
      if (!messageId || !status) continue;
      await postWebhook(session, {
        statusUpdate: true,
        messageId,
        remoteJid: item.key?.remoteJid || "",
        status
      });
    }
  });

  sock.ev.on("message-receipt.update", async (updates) => {
    for (const item of updates) {
      const messageId = item.key?.id;
      const receipt = item.receipt || {};
      if (!messageId) continue;

      let status = "";
      if (receipt.playedTimestamp) status = "played";
      else if (receipt.readTimestamp) status = "read";
      else if (receipt.receiptTimestamp) status = "delivered";
      else if (receipt.status !== undefined && receipt.status !== null) status = receipt.status;
      status = normalizeBaileysStatus(status);
      if (!status) continue;

      await postWebhook(session, {
        statusUpdate: true,
        messageId,
        remoteJid: item.key?.remoteJid || "",
        status
      });
    }
  });

  return session;
}

function sessionPublicState(session) {
  return {
    ok: true,
    sessionKey: session.key,
    status: session.status,
    phone: session.phone,
    qrImage: session.qrImage,
    hasQr: !!session.qr,
    lastError: session.lastError,
    webhookUrl: session.webhookUrl,
    studioId: session.studioId,
    studioName: session.studioName
  };
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

async function waitForSessionStartResult(session, timeoutMs = 12000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    if (session.status === "waiting_qr" || session.status === "connected" || session.qrImage || session.lastError) {
      break;
    }
    await sleep(500);
  }

  return session;
}

app.get("/health", (req, res) => {
  res.json({ ok: true, service: "projetocrm-whatsapp", version: serviceVersion, sessions: sessions.size });
});

app.get("/studios/:sessionKey/status", (req, res) => {
  const key = safeSessionKey(req.params.sessionKey);
  const session = sessions.get(key) || createSession(key);
  res.json(sessionPublicState(session));
});

app.post("/studios/:sessionKey/start", async (req, res) => {
  try {
    const session = await waitForSessionStartResult(await startSession(req.params.sessionKey, req.body || {}));
    res.json(sessionPublicState(session));
  } catch (error) {
    res.status(500).json({ ok: false, error: error.message || "Erro ao iniciar sessao." });
  }
});

app.post("/studios/:sessionKey/logout", async (req, res) => {
  try {
    const key = safeSessionKey(req.params.sessionKey);
    const session = sessions.get(key) || createSession(key);
    session.shouldReconnect = false;
    if (session.reconnectTimer) {
      clearTimeout(session.reconnectTimer);
      session.reconnectTimer = null;
    }
    if (session.sock) {
      await session.sock.logout().catch(() => null);
      session.sock.end?.();
    }
    session.sock = null;
    session.status = "disconnected";
    session.qr = "";
    session.qrImage = "";
    await notifyStatus(session, "disconnected", "Sessao desconectada.");
    res.json(sessionPublicState(session));
  } catch (error) {
    res.status(500).json({ ok: false, error: error.message || "Erro ao desconectar sessao." });
  }
});

app.post("/studios/:sessionKey/reset", async (req, res) => {
  try {
    const key = safeSessionKey(req.params.sessionKey);
    const session = sessions.get(key) || createSession(key);
    session.shouldReconnect = false;
    if (session.reconnectTimer) {
      clearTimeout(session.reconnectTimer);
      session.reconnectTimer = null;
    }
    if (session.sock) {
      await session.sock.logout().catch(() => null);
      session.sock.end?.();
    }

    sessions.delete(key);
    fs.rmSync(path.join(sessionsDir, key), { recursive: true, force: true });

    const freshSession = createSession(key);
    freshSession.webhookUrl = req.body?.webhookUrl || session.webhookUrl || defaultWebhookUrl;
    freshSession.webhookToken = req.body?.webhookToken || session.webhookToken || "";
    freshSession.studioId = req.body?.studioId || session.studioId || null;
    freshSession.studioSlug = req.body?.studioSlug || session.studioSlug || "";
    freshSession.studioName = req.body?.studioName || session.studioName || "";

    res.json(sessionPublicState(freshSession));
  } catch (error) {
    res.status(500).json({ ok: false, error: error.message || "Erro ao limpar sessao." });
  }
});

app.post("/studios/:sessionKey/send", async (req, res) => {
  try {
    const key = safeSessionKey(req.params.sessionKey);
    const session = sessions.get(key);
    if (!session?.sock || session.status !== "connected") {
      return res.status(409).json({ ok: false, error: "WhatsApp nao conectado para este estudio." });
    }

    const numero = String(req.body?.numero || "").replace(/\D/g, "");
    const jid = String(req.body?.jid || "").trim();
    const mensagem = String(req.body?.mensagem || "").trim();
    const media = req.body?.media || null;
    if ((!numero && !jid) || (!mensagem && !media?.base64)) {
      return res.status(422).json({ ok: false, error: "Numero e mensagem obrigatorios." });
    }

    const destinoJid = jid || `${numero}@s.whatsapp.net`;
    const payload = media?.base64
      ? {
          document: Buffer.from(media.base64, "base64"),
          mimetype: media.mime || "application/octet-stream",
          fileName: media.fileName || "arquivo",
          caption: mensagem || undefined
        }
      : { text: mensagem };

    const result = await session.sock.sendMessage(destinoJid, payload);
    res.json({ ok: true, messageId: result?.key?.id || "", remoteJid: result?.key?.remoteJid || destinoJid });
  } catch (error) {
    res.status(500).json({ ok: false, error: error.message || "Erro ao enviar mensagem." });
  }
});

app.listen(port, () => {
  fs.mkdirSync(sessionsDir, { recursive: true });
  console.log(`Servico WhatsApp multi-estudio rodando na porta ${port}`);
});

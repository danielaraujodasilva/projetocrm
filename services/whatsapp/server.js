const {
  default: makeWASocket,
  useMultiFileAuthState,
  fetchLatestBaileysVersion,
  downloadMediaMessage,
  DisconnectReason,
  Browsers
} = require("@whiskeysockets/baileys");

const axios = require("axios");
const express = require("express");
const fs = require("fs");
const path = require("path");
const pino = require("pino");

const app = express();
app.use(express.json({ limit: "30mb" }));

const serviceVersion = "2026-05-15-force-restart";
const port = Number(process.env.WHATSAPP_PORT || 3010);
const defaultWebhookUrl = process.env.WHATSAPP_WEBHOOK_URL || "http://localhost/projetocrm/api/whatsapp_webhook.php";
const sessionsDir = path.join(__dirname, "sessions");
const serviceLogFile = path.join(__dirname, "whatsapp_service.log");
const sessions = new Map();

function appendServiceLog(message, data = {}) {
  const line = `[${new Date().toISOString()}] ${message}${Object.keys(data).length ? " " + JSON.stringify(data) : ""}\n`;
  try {
    fs.appendFileSync(serviceLogFile, line);
  } catch {
    // File logging is best-effort; console output is still captured by the launcher.
  }
}

function safeSessionKey(value) {
  const key = String(value || "").toLowerCase().replace(/[^a-z0-9_-]+/g, "-").replace(/^-+|-+$/g, "");
  if (!key) throw new Error("Chave da sessao invalida");
  return key.slice(0, 120);
}

function jidToDigits(jid) {
  return String(jid || "").split("@")[0].split(":")[0].replace(/\D/g, "");
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
    content?.messageContextInfo?.quotedMessage?.conversation ||
    content?.messageContextInfo?.quotedMessage?.extendedTextMessage?.text ||
    content?.templateMessage?.hydratedTemplate?.hydratedContentText ||
    content?.templateMessage?.hydratedTemplate?.hydratedFooterText ||
    content?.buttonsResponseMessage?.selectedButtonId ||
    content?.buttonsResponseMessage?.selectedDisplayText ||
    content?.listResponseMessage?.singleSelectReply?.selectedRowId ||
    content?.listResponseMessage?.singleSelectReply?.selectedRowDescription ||
    content?.templateButtonReplyMessage?.selectedId ||
    content?.templateButtonReplyMessage?.selectedDisplayText ||
    content?.interactiveResponseMessage?.nativeFlowResponseMessage?.paramsJson ||
    content?.interactiveResponseMessage?.body?.text ||
    content?.interactiveMessage?.nativeFlowResponseMessage?.paramsJson ||
    content?.interactiveMessage?.body?.text ||
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

function normalizeOutboundAudioMime(mime) {
  const value = String(mime || "").toLowerCase().trim();
  if (!value) return "audio/ogg; codecs=opus";
  if (value.startsWith("audio/ogg") || value.startsWith("audio/opus")) return "audio/ogg; codecs=opus";
  return value;
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

function fallbackContactNumberFromJid(jid) {
  const digits = jidToDigits(jid);
  if (digits.length >= 10) {
    return digits;
  }
  return "";
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
    pairingCode: "",
    pairingPhone: "",
    phone: "",
    lastError: "",
    lastEvents: [],
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

function logSession(session, message, data = {}) {
  const event = {
    at: new Date().toISOString(),
    event: message,
    ...data
  };
  session.lastEvents.push(event);
  session.lastEvents = session.lastEvents.slice(-20);
  appendServiceLog(`[${session.key}] ${message}`, data);
  console.log(`[${session.key}] ${message}`, data);
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
    const response = await axios.post(session.webhookUrl, body, { timeout: 60000 });
    return response.data;
  } catch (error) {
    console.error(`[${session.key}] Falha ao chamar webhook:`, error.message);
    return { ok: false, error: error.message };
  }
}

async function notifyStatus(session, status, message = "") {
  await postWebhook(session, { statusEvent: true, status, message });
}

function notifyStatusAsync(session, status, message = "") {
  notifyStatus(session, status, message).catch((error) => {
    logSession(session, "Falha ao notificar status no webhook", { error: error.message });
  });
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

  const authDir = path.join(sessionsDir, session.key, "auth_info");
  const credsPath = path.join(authDir, "creds.json");
  let shouldResetAuth = String(session.lastError || "").includes("401") || String(session.lastError || "").toLowerCase().includes("loggedout");
  if (!shouldResetAuth && fs.existsSync(credsPath)) {
    try {
      const creds = JSON.parse(fs.readFileSync(credsPath, "utf8"));
      shouldResetAuth = creds?.registered === false;
    } catch {
      shouldResetAuth = true;
    }
  }
  if (shouldResetAuth && fs.existsSync(authDir)) {
    try {
      fs.rmSync(authDir, { recursive: true, force: true });
      logSession(session, "Auth antiga removida para forcar novo pareamento");
      session.lastError = "";
      session.pairingCode = "";
      session.pairingPhone = "";
    } catch (error) {
      logSession(session, "Falha ao remover auth antiga", { error: error.message });
    }
  }

  session.status = "starting";
  session.lastError = "";
  session.pairingCode = "";
  session.startedAt = Math.floor(Date.now() / 1000);
  fs.mkdirSync(authDir, { recursive: true });

  const { state, saveCreds } = await useMultiFileAuthState(authDir);
  const { version } = await fetchLatestBaileysVersion();

  const sock = makeWASocket({
    version,
    auth: state,
    printQRInTerminal: false,
    browser: Browsers.windows("Chrome"),
    connectTimeoutMs: 60000,
    defaultQueryTimeoutMs: 60000,
    keepAliveIntervalMs: 20000,
    logger: pino({ level: "silent" }),
    markOnlineOnConnect: false,
    syncFullHistory: false
  });
  session.sock = sock;

  sock.ev.on("creds.update", saveCreds);

  sock.ev.on("connection.update", async (update) => {
    const { connection, lastDisconnect } = update;
    const disconnectCode = lastDisconnect?.error?.output?.statusCode;
    const disconnectMessage = lastDisconnect?.error?.message || "";

    logSession(session, "connection.update", {
      connection: connection || "",
      disconnectCode: disconnectCode || "",
      disconnectMessage
    });

    if (connection === "open") {
      session.status = "connected";
      session.pairingCode = "";
      session.pairingPhone = "";
      session.restartAttempts = 0;
      session.shouldReconnect = true;
      session.phone = jidToDigits(sock.user?.id || "");
      if (!session.phone && session.pairingPhone) {
        session.phone = session.pairingPhone;
      }
      notifyStatusAsync(session, "connected", "WhatsApp conectado.");
      logSession(session, "WhatsApp conectado", { phone: session.phone || "" });
    }

    if (connection === "close") {
      const code = disconnectCode;
      const loggedOut = code === DisconnectReason.loggedOut;
      const badSession = [
        DisconnectReason.badSession,
        DisconnectReason.multideviceMismatch,
        DisconnectReason.forbidden
      ].includes(code);
      const shouldReconnect = session.shouldReconnect !== false && !loggedOut && !badSession;
      if (session.sock === sock) {
        session.sock = null;
      }
      session.pairingCode = "";
      session.lastError = [disconnectMessage, code ? `codigo ${code}` : ""].filter(Boolean).join(" | ");
      logSession(session, "connection.closed", {
        code: code || "",
        reason: DisconnectReason[code] || "",
        detail: disconnectMessage || ""
      });

      if (badSession) {
        fs.rmSync(path.join(sessionsDir, session.key), { recursive: true, force: true });
        session.status = "disconnected";
        notifyStatusAsync(session, "disconnected", "Sessao invalida removida. Gere um novo codigo de pareamento.");
        return;
      }

      if (shouldReconnect) {
        session.status = "starting";
        session.restartAttempts = (session.restartAttempts || 0) + 1;
        const delay = Math.min(1000 * session.restartAttempts, 5000);
        logSession(session, "Conexao fechada; tentando reconectar", { delay });
        notifyStatusAsync(session, "starting", "Conexao fechada; tentando reconectar automaticamente.");
        if (session.reconnectTimer) {
          clearTimeout(session.reconnectTimer);
        }
        const timer = setTimeout(() => {
          session.reconnectTimer = null;
          startSession(session.key, sessionStartConfig(session)).catch((error) => {
            session.status = "disconnected";
            session.lastError = error.message || "Falha ao reconectar.";
            logSession(session, "Falha ao reconectar", { error: session.lastError });
            notifyStatusAsync(session, "disconnected", session.lastError);
          });
        }, delay);
        timer.unref?.();
        session.reconnectTimer = timer;
        return;
      }

      session.status = "disconnected";
      notifyStatusAsync(session, session.status, loggedOut ? "Sessao encerrada." : "Conexao fechada; reconecte pelo painel se necessario.");
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
      if (!identity.numero) {
        const fallbackPhone = fallbackContactNumberFromJid(jid);
        if (fallbackPhone) {
          identity.numero = fallbackPhone;
          identity.source = "jid_fallback";
        }
      }
      if (!identity.numero) continue;
      if (jid.endsWith("@lid") && identity.source !== "lid_fallback") {
        session.lidToPhone.set(jidToDigits(jid), identity.numero);
      }

      const texto = extractText(msg.message);
      const mediaInfo = getMediaMessage(msg.message);
      const mediaPayload = {};
      const messageKind = Object.keys(getMessageContent(msg.message) || {}).slice(0, 8).join(",") || "unknown";

      if (mediaInfo) {
        try {
          const buffer = await downloadMediaMessage(
            msg,
            "buffer",
            {},
            { logger: pino({ level: "silent" }) }
          );
          mediaPayload.mediaBase64 = buffer.toString("base64");
          mediaPayload.mediaMime = mediaInfo.payload.mimetype || mediaInfo.payload.mimeType || mediaInfo.payload.mime || "";
          mediaPayload.mediaFileName = mediaInfo.payload.fileName || mediaInfo.payload.filename || `${mediaInfo.type}_${key.id || Date.now()}`;
          mediaPayload.mediaCaption = mediaInfo.payload.caption || "";
        } catch (error) {
          console.error(`[${session.key}] Erro ao baixar midia:`, error.message);
        }
      }

      if (!String(texto || "").trim() && !mediaInfo) {
        logSession(session, "Mensagem recebida sem texto/midia descartada", {
          remoteJid: jid,
          fromMe: !!key.fromMe,
          messageKind,
          messageId: key.id || "",
          isStatus: !!key.isStatus
        });
        continue;
      }

      logSession(session, "Mensagem recebida pronta para webhook", {
        remoteJid: jid,
        fromMe: !!key.fromMe,
        tipoMensagem: mediaInfo?.type || "texto",
        temTexto: !!String(texto || "").trim(),
        messageKind,
        messageId: key.id || ""
      });

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
    pairingCode: session.pairingCode,
    pairingPhone: session.pairingPhone,
    lastError: session.lastError,
    lastEvents: session.lastEvents,
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
    if (session.status === "waiting_qr" || session.status === "connected" || session.lastError) {
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
    const key = safeSessionKey(req.params.sessionKey);
    const current = sessions.get(key) || createSession(key);
    current.shouldReconnect = false;
    if (current.reconnectTimer) {
      clearTimeout(current.reconnectTimer);
      current.reconnectTimer = null;
    }
    if (current.sock) {
      await current.sock.logout().catch(() => null);
      current.sock.end?.();
      current.sock = null;
    }

    sessions.delete(key);
    fs.rmSync(path.join(sessionsDir, key), { recursive: true, force: true });

    const session = await waitForSessionStartResult(await startSession(key, req.body || {}));
    res.json(sessionPublicState(session));
  } catch (error) {
    res.status(500).json({ ok: false, error: error.message || "Erro ao iniciar sessao." });
  }
});

app.post("/studios/:sessionKey/pairing-code", async (req, res) => {
  try {
    const key = safeSessionKey(req.params.sessionKey);
    const number = String(req.body?.numero || req.body?.phone || "").replace(/\D/g, "");
    if (number.length < 10) {
      return res.status(422).json({ ok: false, error: "Informe o telefone com DDI e DDD, somente numeros." });
    }

    const previousSession = sessions.get(key) || createSession(key);
    previousSession.shouldReconnect = false;
    if (previousSession.reconnectTimer) {
      clearTimeout(previousSession.reconnectTimer);
      previousSession.reconnectTimer = null;
    }
    if (previousSession.sock) {
      await previousSession.sock.logout().catch(() => null);
      previousSession.sock.end?.();
      previousSession.sock = null;
    }

    sessions.delete(key);
    fs.rmSync(path.join(sessionsDir, key), { recursive: true, force: true });

    const session = await startSession(key, {
      ...sessionStartConfig(previousSession),
      ...(req.body || {})
    });
    if (!session.sock) {
      return res.status(409).json({ ok: false, error: "Sessao WhatsApp ainda nao iniciou." });
    }

    if (session.sock.authState?.creds?.registered) {
      return res.json(sessionPublicState(session));
    }

    logSession(session, "Solicitando codigo de pareamento", { phone: number });
    const requestCode = async () => {
      const code = await session.sock.requestPairingCode(number);
      session.pairingCode = String(code || "").replace(/\s+/g, "").trim();
      session.pairingPhone = number;
      session.status = "waiting_qr";
      session.lastError = "";
      logSession(session, "Codigo de pareamento gerado", { phone: number });
      return sessionPublicState(session);
    };

    await sleep(1500);
    try {
      return res.json(await requestCode());
    } catch (firstError) {
      logSession(session, "Falha inicial ao gerar codigo; tentando novamente", { error: firstError.message || String(firstError) });
      await sleep(2500);
      try {
        return res.json(await requestCode());
      } catch (secondError) {
        throw secondError;
      }
    }
  } catch (error) {
    console.error(`[${safeSessionKey(req.params.sessionKey)}] Falha ao gerar codigo de pareamento:`, error);
    res.status(500).json({ ok: false, error: error.message || "Erro ao gerar codigo de pareamento." });
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
    session.pairingCode = "";
    session.pairingPhone = "";
    notifyStatusAsync(session, "disconnected", "Sessao desconectada.");
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
    freshSession.lastEvents = session.lastEvents || [];
    logSession(freshSession, "Sessao limpa pelo painel");

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
  let payload = { text: mensagem };
  if (media?.base64) {
    const buffer = Buffer.from(media.base64, "base64");
    const mime = String(media.mime || "application/octet-stream");
    const fileName = String(media.fileName || "arquivo");
    const kind = String(media.kind || "").toLowerCase();
    if (kind === "image" || mime.startsWith("image/")) {
      payload = { image: buffer, mimetype: mime, caption: mensagem || undefined };
    } else if (kind === "video" || mime.startsWith("video/")) {
      payload = { video: buffer, mimetype: mime, caption: mensagem || undefined };
    } else if (kind === "audio" || mime.startsWith("audio/")) {
      payload = { audio: buffer, mimetype: normalizeOutboundAudioMime(mime), ptt: /audio_\d+\.(ogg|opus|webm)$/i.test(fileName) };
    } else {
      payload = { document: buffer, mimetype: mime, fileName, caption: mensagem || undefined };
    }
  }

  const result = await session.sock.sendMessage(destinoJid, payload);
    res.json({ ok: true, messageId: result?.key?.id || "", remoteJid: result?.key?.remoteJid || destinoJid });
  } catch (error) {
    res.status(500).json({ ok: false, error: error.message || "Erro ao enviar mensagem." });
  }
});

app.listen(port, () => {
  fs.mkdirSync(sessionsDir, { recursive: true });
  appendServiceLog("Servico WhatsApp iniciado", { port, version: serviceVersion });
  console.log(`Servico WhatsApp multi-estudio rodando na porta ${port}`);
  try {
    for (const entry of fs.readdirSync(sessionsDir, { withFileTypes: true })) {
      if (!entry.isDirectory()) continue;
      const sessionKey = entry.name;
      const authDir = path.join(sessionsDir, sessionKey, "auth_info");
      if (!fs.existsSync(authDir)) continue;
      startSession(sessionKey).catch((error) => {
        console.error(`[${sessionKey}] Falha ao restaurar sessao no inicio:`, error.message);
      });
    }
  } catch (error) {
    console.error("Falha ao restaurar sessoes existentes:", error.message);
  }
});

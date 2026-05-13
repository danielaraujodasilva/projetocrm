import fs from "node:fs/promises";
import path from "node:path";
import process from "node:process";
import { fileURLToPath } from "node:url";
import puppeteer from "puppeteer-core";

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const rootDir = path.resolve(__dirname, "../..");
const storageDir = path.join(rootDir, "storage", "whatsapp-web-collector");
const profileDir = path.join(storageDir, "chrome-profile");
const outputDir = path.join(storageDir, "exports");

const options = parseArgs(process.argv.slice(2));
const limit = Number(options.limit || process.env.WHATSAPP_COLLECT_LIMIT || 50);
const messageScrolls = Number(options.messageScrolls || options.scrolls || process.env.WHATSAPP_COLLECT_MESSAGE_SCROLLS || 15);
const chatScrolls = Number(options.chatScrolls || 0);
const loginTimeoutMs = Number(options.loginTimeoutSeconds || 1200) * 1000;
const headless = options.headless === "1" || options.headless === "true";

await fs.mkdir(outputDir, { recursive: true });
await fs.mkdir(profileDir, { recursive: true });

const browser = await puppeteer.launch({
  executablePath: options.chrome || findChrome(),
  userDataDir: profileDir,
  headless,
  defaultViewport: null,
  args: [
    "--start-maximized",
    "--disable-notifications",
    "--no-first-run",
    "--no-default-browser-check"
  ]
});

const page = await browser.newPage();
page.setDefaultTimeout(30000);
await page.goto("https://web.whatsapp.com", { waitUntil: "domcontentloaded" });

console.log("WhatsApp Web aberto.");
console.log(`Se aparecer QR Code, escaneie no celular. O script vai aguardar login por ate ${Math.round(loginTimeoutMs / 1000)} segundos.`);
await waitForWhatsAppReady(page, loginTimeoutMs);

const chats = await collectChatList(page, limit, chatScrolls);
console.log(`Conversas detectadas para teste: ${chats.length}`);

const result = {
  collected_at: new Date().toISOString(),
  source: "whatsapp_web",
  limit,
  message_scrolls: messageScrolls,
  chats: []
};

for (let index = 0; index < chats.length; index++) {
  const chat = chats[index];
  console.log(`Coletando ${index + 1}/${chats.length}: ${chat.title || chat.preview || "conversa sem nome"}`);
  await clickChatByIndex(page, chat.index);
  await waitForConversationOpen(page);
  await scrollMessagesUp(page, messageScrolls);
  const messages = await extractMessages(page);
  const header = await extractConversationHeader(page);
  result.chats.push({
    chat_list_title: chat.title,
    chat_list_preview: chat.preview,
    header,
    messages
  });
}

const outputFile = path.join(outputDir, `whatsapp-web-preview-${timestampForFile()}.json`);
await fs.writeFile(outputFile, JSON.stringify(result, null, 2), "utf8");
console.log(`Arquivo salvo: ${outputFile}`);
console.log(`Conversas salvas: ${result.chats.length}`);
console.log(`Mensagens salvas: ${result.chats.reduce((sum, chat) => sum + chat.messages.length, 0)}`);

if (!options.keepOpen) {
  await browser.close();
}

function parseArgs(args) {
  const parsed = {};
  for (const arg of args) {
    const match = arg.match(/^--([^=]+)=(.*)$/);
    if (match) {
      parsed[match[1]] = match[2];
    } else if (arg.startsWith("--")) {
      parsed[arg.slice(2)] = true;
    }
  }
  return parsed;
}

function findChrome() {
  const candidates = [
    "C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe",
    "C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe",
    "C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe"
  ];
  for (const candidate of candidates) {
    try {
      return requireAccess(candidate);
    } catch {
      // Try next browser path.
    }
  }
  throw new Error("Chrome/Edge nao encontrado. Informe --chrome=C:\\caminho\\chrome.exe");
}

function requireAccess(file) {
  return file;
}

async function waitForWhatsAppReady(page, timeoutMs) {
  const started = Date.now();
  while (Date.now() - started < timeoutMs) {
    const state = await page.evaluate(() => {
      const text = document.body?.innerText || "";
      const hasApp = Boolean(document.querySelector("#app"));
      const hasChatList = Boolean(document.querySelector('[aria-label*="Lista de conversas"], [aria-label*="Chat list"], [role="grid"], [role="list"]'));
      const hasQr = text.includes("Use o WhatsApp no seu computador") || text.includes("Use WhatsApp on your computer") || Boolean(document.querySelector("canvas"));
      return { hasApp, hasChatList, hasQr, text: text.slice(0, 250) };
    });

    if (state.hasChatList && !state.hasQr) {
      console.log("Login detectado.");
      return;
    }
    await sleep(2500);
  }
  throw new Error("Timeout aguardando login no WhatsApp Web.");
}

async function collectChatList(page, limit, chatScrolls) {
  for (let i = 0; i < chatScrolls; i++) {
    await page.evaluate(() => {
      const pane = document.querySelector("#pane-side") || document.querySelector('[aria-label*="Lista de conversas"]') || document.querySelector('[aria-label*="Chat list"]');
      pane?.scrollBy(0, 650);
    });
    await sleep(700);
  }

  return page.evaluate((limitValue) => {
    const rows = Array.from(document.querySelectorAll('#pane-side [role="gridcell"], #pane-side [role="listitem"], [aria-label*="Lista de conversas"] [role="gridcell"], [aria-label*="Lista de conversas"] [role="listitem"]'))
      .filter((row) => {
        const text = cleanChatLines(row.innerText || "");
        if (text.length < 2) return false;
        if (text.length === 1 && /^\d+$/.test(text[0])) return false;
        const className = String(row.className || "");
        return !className.includes("_ak8o") && !className.includes("_ak8i");
      });
    return rows.slice(0, limitValue).map((row, index) => {
      const text = cleanChatLines(row.innerText || "");
      return {
        index,
        title: text[0] || "",
        preview: text.slice(1, 5).join(" | "),
        text
      };
    }).filter((chat) => chat.title !== "");

    function cleanChatLines(value) {
      return value.split("\n")
        .map((line) => line.trim())
        .filter(Boolean)
        .filter((line) => !/^\d+\s+mensagens?\s+n[ãa]o\s+lidas?$/i.test(line))
        .filter((line) => !/^\d+\s+messages?\s+unread$/i.test(line));
    }
  }, limit);
}

async function clickChatByIndex(page, index) {
  const rect = await page.evaluate((chatIndex) => {
    const rows = Array.from(document.querySelectorAll('#pane-side [role="gridcell"], #pane-side [role="listitem"], [aria-label*="Lista de conversas"] [role="gridcell"], [aria-label*="Lista de conversas"] [role="listitem"]'))
      .filter((row) => {
        const text = cleanChatLines(row.innerText || "");
        if (text.length < 2) return false;
        if (text.length === 1 && /^\d+$/.test(text[0])) return false;
        const className = String(row.className || "");
        return !className.includes("_ak8o") && !className.includes("_ak8i");
      });
    const row = rows[chatIndex];
    if (!row) {
      throw new Error(`Conversa ${chatIndex} nao encontrada na lista visivel.`);
    }
    row.scrollIntoView({ block: "center" });
    const box = row.getBoundingClientRect();
    return { x: box.left + Math.min(120, box.width / 2), y: box.top + box.height / 2 };

    function cleanChatLines(value) {
      return value.split("\n")
        .map((line) => line.trim())
        .filter(Boolean)
        .filter((line) => !/^\d+\s+mensagens?\s+n[ãa]o\s+lidas?$/i.test(line))
        .filter((line) => !/^\d+\s+messages?\s+unread$/i.test(line));
    }
  }, index);
  await page.mouse.click(rect.x, rect.y);
}

async function waitForConversationOpen(page) {
  const started = Date.now();
  while (Date.now() - started < 30000) {
    const ok = await page.evaluate(() => Boolean(document.querySelector("#main")));
    if (ok) {
      await sleep(900);
      return;
    }
    await sleep(500);
  }
  throw new Error("Conversa nao abriu dentro do tempo esperado.");
}

async function scrollMessagesUp(page, times) {
  for (let i = 0; i < times; i++) {
    await page.evaluate(() => {
      const main = document.querySelector("#main");
      const scroller = main?.querySelector('[tabindex="-1"]') || main?.querySelector('[role="application"]') || main;
      scroller?.scrollBy(0, -900);
    });
    await sleep(900);
  }
}

async function extractConversationHeader(page) {
  return page.evaluate(() => {
    const main = document.querySelector("#main");
    const header = main?.querySelector("header");
    const text = (header?.innerText || "").split("\n").map((line) => line.trim()).filter(Boolean);
    return {
      title: text[0] || "",
      subtitle: text.slice(1).join(" | ")
    };
  });
}

async function extractMessages(page) {
  return page.evaluate(() => {
    const main = document.querySelector("#main");
    const nodes = Array.from(main?.querySelectorAll('[data-id]') || []);
    const seen = new Set();
    const messages = [];
    const mainRect = main?.getBoundingClientRect();
    const centerX = mainRect ? mainRect.left + mainRect.width / 2 : window.innerWidth / 2;

    for (const node of nodes) {
      const raw = (node.innerText || "").trim();
      if (!raw) continue;
      if (isSystemNotice(raw)) continue;
      const dataId = node.getAttribute("data-id") || "";
      const key = dataId || raw;
      if (seen.has(key)) continue;
      seen.add(key);
      const box = node.getBoundingClientRect();
      const direction = node.classList.contains("message-out") || dataId.includes("true_") || (box.left + box.width / 2 > centerX) ? "out" : "in";
      const lines = raw.split("\n").map((line) => line.trim()).filter(Boolean);
      messages.push({
        id: dataId,
        direction,
        raw_text: raw,
        lines,
        text: cleanMessageText(lines),
        time: extractMessageTime(lines)
      });
    }

    return messages;

    function isSystemNotice(value) {
      return value.includes("criptografia de ponta a ponta")
        || value.includes("serviço seguro da Meta")
        || value.includes("A IA da Meta recebe")
        || value.includes("As mensagens e ligações agora são protegidas");
    }

    function extractMessageTime(lines) {
      const last = lines[lines.length - 1] || "";
      return /^\d{1,2}:\d{2}$/.test(last) ? last : "";
    }

    function cleanMessageText(lines) {
      const textLines = [...lines];
      if (/^\d{1,2}:\d{2}$/.test(textLines[textLines.length - 1] || "")) {
        textLines.pop();
      }
      return textLines.join("\n").trim();
    }
  });
}

function timestampForFile() {
  return new Date().toISOString().replace(/[:.]/g, "-");
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

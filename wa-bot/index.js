require("dotenv").config();

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason
} = require("@whiskeysockets/baileys");

const { Boom } = require("@hapi/boom");
const P = require("pino");
const QRCode = require("qrcode-terminal");
const axios = require("axios");

const customerStates = new Map();
let lastEscalatedSession = null;

function detectLanguage(text) {
    const normalized = (text || "").toLowerCase();

    const indonesianWords = [
        'halo', 'hai', 'terima kasih', 'makasih', 'tolong', 'mohon', 'bagaimana', 'cara',
        'apa', 'apakah', 'saya', 'anda', 'kami', 'bisa', 'dapat', 'silakan', 'langkah',
        'perlu', 'ada', 'apa saja', 'dimana', 'kapan', 'siapa', 'kenapa', 'pakai', 'gunakan',
        'bisakah', 'gimana', 'ini', 'itu'
    ];

    const englishWords = [
        'hello', 'hi', 'thank you', 'thanks', 'please', 'how', 'how can', 'can you', 'what',
        'where', 'when', 'why', 'need', 'help', 'guide', 'step', 'steps', 'use', 'please wait',
        'admin', 'customer', 'service', 'support'
    ];

    let indonesianScore = 0;
    let englishScore = 0;

    for (const word of indonesianWords) {
        if (normalized.includes(word)) {
            indonesianScore++;
        }
    }

    for (const word of englishWords) {
        if (normalized.includes(word)) {
            englishScore++;
        }
    }

    return indonesianScore > englishScore ? 'id' : 'en';
}

function normalizeText(text) {
    return (text || "").trim().toLowerCase();
}

function isGreetingLike(text) {
    const normalized = normalizeText(text);
    const greetingKeywords = [
        'halo', 'hai', 'hello', 'hi', 'selamat pagi', 'selamat siang',
        'selamat sore', 'selamat malam', 'assalamualaikum', 'salam',
        'terima kasih', 'makasih', 'thanks', 'thank you'
    ];

    return greetingKeywords.some((keyword) => normalized.includes(keyword));
}

function isDirectAdminRequest(text) {
    const normalized = normalizeText(text);
    const adminKeywords = [
        'admin', 'hubungi admin', 'contact admin', 'talk to admin',
        'connect admin', 'customer service', 'support'
    ];

    return adminKeywords.some((keyword) => normalized.includes(keyword));
}

function getGreetingReply(text) {
    const isId = detectLanguage(text) === 'id';

    return isId
        ? 'Halo 👋\n\nSelamat datang di Customer Service PT Siber Sinergi Teknologi.\n\nAda yang bisa kami bantu hari ini?'
        : 'Hello 👋\n\nWelcome to Customer Service of PT Siber Sinergi Teknologi.\n\nHow can we help you today?';
}

function getCustomerState(phone) {
    if (!customerStates.has(phone)) {
        customerStates.set(phone, {
            menuShown: false,
            escalationMenuShown: false,
            escalatedToAdmin: false,
            botPaused: false,
            sessionJid: ""
        });
    }

    return customerStates.get(phone);
}

function resetCustomerState(phone) {
    const state = getCustomerState(phone);
    state.menuShown = false;
    state.escalationMenuShown = false;
    state.escalatedToAdmin = false;
    state.botPaused = false;
    state.sessionJid = "";
}

function getPhoneFromJid(jid, msg) {
    if (!jid) return "";

    const raw = String(jid || "")
        .replace(/@.+$/, "")
        .replace(/:.+$/, "")
        .trim();

    if (!raw) return "";

    const participant = String(msg?.key?.participant || "")
        .replace(/@.+$/, "")
        .replace(/:.+$/, "")
        .trim();

    if (participant && /^\d+$/.test(participant) && participant.length >= 8) {
        return participant;
    }

    return /^\d+$/.test(raw) ? raw : "";
}

function shouldShowTopicMenu(text, phone) {
    const state = getCustomerState(phone);

    if (state.menuShown) {
        return false;
    }

    if (isGreetingLike(text) || isDirectAdminRequest(text)) {
        return false;
    }

    return true;
}

function shouldShowEscalationMenu(text, phone) {
    const state = getCustomerState(phone);

    if (state.escalationMenuShown) {
        return false;
    }

    if (isGreetingLike(text) || isDirectAdminRequest(text)) {
        return false;
    }

    return true;
}

function isEndSessionText(text) {
    const normalized = normalizeText(text);
    const endKeywords = [
        'akhiri', 'akhiri sesi', 'end', 'done', 'selesai', 'finish', 'close'
    ];

    return endKeywords.some((keyword) => normalized.includes(keyword));
}

function markTopicMenuShown(phone) {
    const state = getCustomerState(phone);
    state.menuShown = true;
}

function markEscalationMenuShown(phone) {
    const state = getCustomerState(phone);
    state.escalationMenuShown = true;
}

async function sendEscalationMenu(sock, jid, text) {
    const isId = detectLanguage(text) === 'id';
    const prompt = isId
        ? 'Saya belum yakin bisa membantu sepenuhnya.\nPilih opsi berikut:\n1) Chat Admin\n2) Kirim Email ke eyre.hypercon@gmail.com\n\nKalau pilih nomor 2, cukup balas dengan kata "email" atau pilih nomor 2.'
        : 'I’m not fully sure I can help with this yet.\nChoose one option:\n1) Chat Admin\n2) Send Email to eyre.hypercon@gmail.com\n\nIf you choose option 2, just reply with "email" or select 2.';

    await sock.sendMessage(jid, { text: prompt }).catch(() => {});
}

async function sendFallbackReply(sock, jid, text) {
    const isId = detectLanguage(text) === 'id';
    const fallback = isId
        ? 'Maaf, sistem sedang mengalami gangguan sementara. Saya belum bisa memproses pesan Anda saat ini.'
        : 'Sorry, the system is temporarily unavailable. I could not process your message right now.';

    await sock.sendMessage(jid, { text: fallback }).catch(() => {});
}

function shouldOfferEscalationMenu(text, responseData) {
    if (!responseData) return false;

    const normalizedText = normalizeText(text);
    if (!normalizedText) return false;
    if (isGreetingLike(text) || isDirectAdminRequest(text)) return false;

    const shouldEscalate = Boolean(responseData?.should_escalate);
    const confidence = Number(responseData?.confidence || 0);
    const replyText = String(responseData?.reply || "");
    const hasWeakFallbackSignal = /maaf|sorry|tidak tersedia|not available|tidak dapat|not sure|belum yakin|gangguan|sistem sedang mengalami|manual|company manual|requested information is not available/i.test(replyText);

    return shouldEscalate || confidence < 50 || (hasWeakFallbackSignal && confidence < 80);
}

async function tryEndAdminSession(sock, jid, phone, text) {
    const normalized = normalizeText(text);

    if (!isEndSessionText(normalized)) {
        return false;
    }

    const targetPhone = (text.match(/\b\d{8,15}\b/g) || [])[0] || lastEscalatedSession?.phone || null;

    if (!targetPhone && !lastEscalatedSession) {
        return false;
    }

    const targetState = targetPhone ? getCustomerState(targetPhone) : null;

    if (targetState) {
        targetState.botPaused = false;
        targetState.escalatedToAdmin = false;
        targetState.sessionJid = targetState.sessionJid || lastEscalatedSession?.jid || "";
    }

    if (lastEscalatedSession?.jid) {
        await sock.sendMessage(lastEscalatedSession.jid, {
            text: getGreetingReply('halo')
        }).catch(() => {});
    }

    lastEscalatedSession = null;
    return true;
}

async function handleButtonChoice(sock, jid, phone, text) {
    const normalized = normalizeText(text);
    const state = getCustomerState(phone);

    if (await tryEndAdminSession(sock, jid, phone, text)) {
        return true;
    }

    const isAdminChoice = normalized === 'escalate_admin' || normalized === '1' || normalized === 'satu'
        || normalized.includes('admin') || normalized.includes('hubungi admin') || normalized.includes('contact admin') || normalized.includes('chat admin');

    if (isAdminChoice) {
        state.escalatedToAdmin = true;
        state.botPaused = true;
        state.escalationMenuShown = true;
        state.sessionJid = jid;
        lastEscalatedSession = { phone, jid };
        const adminReply = detectLanguage(text) === 'id'
            ? 'Sesi Anda telah dialihkan ke Admin. Bot tidak akan membalas pesan Anda lagi sampai sesi ditutup. Balas dengan kata "akhiri" jika admin sudah selesai.'
            : 'Your session has been handed over to an admin. The bot will not reply again until the session is closed. Reply with "akhiri" when the admin is done.';

        await sock.sendMessage(jid, { text: adminReply }).catch(() => {});
        return true;
    }

    const isEmailChoice = normalized === 'escalate_email' || normalized === '2' || normalized === 'dua'
        || normalized.includes('email') || normalized.includes('kirim email') || normalized.includes('send email');

    if (isEmailChoice) {
        try {
            await axios.post(
                'http://127.0.0.1:8000/api/chat/escalate-email',
                {
                    phone,
                    name: phone,
                    message: 'Escalasi dari WhatsApp',
                    reply: 'Pilihan user: kirim email'
                },
                { timeout: 10000 }
            );

            const emailReply = detectLanguage(text) === 'id'
                ? 'Email eskalasi telah dikirim ke eyre.hypercon@gmail.com.'
                : 'The escalation email has been sent to eyre.hypercon@gmail.com.';

            await sock.sendMessage(jid, { text: emailReply }).catch(() => {});
        } catch (err) {
            const emailReply = detectLanguage(text) === 'id'
                ? 'Maaf, email eskalasi belum berhasil dikirim. Silakan hubungi admin secara manual.'
                : 'Sorry, the escalation email could not be sent. Please contact the admin manually.';

            await sock.sendMessage(jid, { text: emailReply }).catch(() => {});
        }
        return true;
    }

    return false;
}

async function startBot() {

    const { state, saveCreds } =
        await useMultiFileAuthState("session");

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: "silent" }),
        printQRInTerminal: false,
        syncFullHistory: false,
        markOnlineOnConnect: false
    });

    sock.ev.on("creds.update", saveCreds);

    sock.ev.on("connection.update", ({ connection, lastDisconnect, qr }) => {

        if (qr) {
            console.clear();
            console.log("Scan QR berikut :\n");
            QRCode.generate(qr, { small: true });
        }

        if (connection === "connecting") {
            console.log("🟡 Connecting...");
        }

        if (connection === "open") {
            console.log("✅ WhatsApp Connected");
        }

        if (connection === "close") {

            const reason =
                lastDisconnect?.error instanceof Boom
                    ? lastDisconnect.error.output.statusCode
                    : 0;

            console.log("❌ Disconnected :", reason);

            if (reason !== DisconnectReason.loggedOut) {

                console.log("🔄 Reconnecting...");

                setTimeout(() => {
                    startBot();
                }, 3000);

            } else {

                console.log("⚠ Session Logout, Scan QR Lagi.");

            }

        }

    });

    sock.ev.on("messages.upsert", async (event) => {

        try {

            console.log("\n==============================");
            console.log("EVENT :", event.type);

            const msg = event.messages?.[0];

            if (!msg) return;

            if (!msg.message) return;

            if (msg.key.fromMe) return;

            const jid = msg.key.remoteJid;

            if (!jid) return;

            if (
                jid.endsWith("@g.us") ||
                jid.endsWith("@broadcast")
            ) {
                return;
            }

            const phone = getPhoneFromJid(jid, msg);

            const name = msg.pushName || "Customer";

            let text = "";

            if (msg.message.conversation) {
                text = msg.message.conversation;
            }
            else if (msg.message.extendedTextMessage) {
                text = msg.message.extendedTextMessage.text;
            }
            else if (msg.message.imageMessage) {
                text = msg.message.imageMessage.caption || "";
            }
            else if (msg.message.videoMessage) {
                text = msg.message.videoMessage.caption || "";
            }
            else if (msg.message.buttonsResponseMessage) {
                text = msg.message.buttonsResponseMessage.selectedButtonId;
            }
            else if (msg.message.listResponseMessage) {
                text =
                    msg.message.listResponseMessage
                        .singleSelectReply
                        ?.selectedRowId || "";
            }

            text = text.trim();

            if (!text) return;

            console.log("==============================");
            console.log("📩 Pesan Masuk");
            console.log("Nama  :", name);
            console.log("Phone :", phone);
            console.log("Pesan :", text);
            console.log("==============================");

            const state = getCustomerState(phone);

            if (isGreetingLike(text)) {
                resetCustomerState(phone);
            }

            const endedSession = await tryEndAdminSession(sock, jid, phone, text);
            if (endedSession) {
                return;
            }

            if (state.botPaused && !isEndSessionText(text)) {
                await sock.sendMessage(jid, {
                    text: detectLanguage(text) === 'id'
                        ? 'Sesi sedang ditangani Admin. Balas dengan kata "akhiri" jika sesi sudah selesai.'
                        : 'The admin session is currently active. Reply with "akhiri" if the session has finished.'
                }).catch(() => {});
                return;
            }

            const buttonHandled = await handleButtonChoice(sock, jid, phone, text);
            if (buttonHandled) {
                return;
            }

            await sock.sendPresenceUpdate("composing", jid).catch(() => {});

            const isGreeting = isGreetingLike(text);
            const isAdminRequest = isDirectAdminRequest(text);

            if (isGreeting) {
                await sock.sendMessage(jid, {
                    text: getGreetingReply(text)
                }).catch(() => {});
                return;
            }

            let response;
            try {
                response = await axios.post(
                    "http://127.0.0.1:8000/api/chat",
                    {
                        phone,
                        name,
                        message: text
                    },
                    {
                        timeout: 15000
                    }
                );
            }
            catch (err) {
                console.log("\n===== BACKEND TIMEOUT / ERROR =====");
                console.log(err?.message || err);
                await sock.sendPresenceUpdate("paused", jid).catch(() => {});
                await sendFallbackReply(sock, jid, text);
                await sendEscalationMenu(sock, jid, text);
                markEscalationMenuShown(phone);
                return;
            }

            console.log("\nLaravel Reply:");
            console.log(response.data.reply);

            await sock.sendPresenceUpdate("paused", jid).catch(() => {});

            const botReply = response?.data?.reply || 'Maaf, saya belum bisa memproses permintaan Anda saat ini.';

            await sock.sendMessage(jid, {
                text: botReply
            });

            const shouldEscalate = shouldOfferEscalationMenu(text, response?.data);

            if (shouldEscalate && shouldShowEscalationMenu(text, phone)) {
                await sendEscalationMenu(sock, jid, text);
                markEscalationMenuShown(phone);
            }

            console.log("✅ Reply berhasil dikirim");

        }
        catch (err) {

            console.log("\n===== ERROR =====");

            if (err.response) {

                console.log(err.response.data);

            } else {

                console.log(err);

            }

        }

    });

}

startBot();
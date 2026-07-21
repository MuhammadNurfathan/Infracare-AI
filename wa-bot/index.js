require('dotenv').config();

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason
} = require('@whiskeysockets/baileys');

const { Boom } = require('@hapi/boom');
const P = require('pino');
const QRCode = require('qrcode-terminal');
const axios = require('axios');

const customerStates = new Map();

// ==================== ADMIN CONFIG ====================
const ADMIN_NUMBERS = (process.env.ADMIN_NUMBERS || '')
    .split(',')
    .map(n => n.trim())
    .filter(Boolean);

function isAdminNumber(phone) {
    return ADMIN_NUMBERS.includes(phone);
}

function getOwnNumber(sock) {
    return String(sock.user?.id || '')
        .split(':')[0]
        .replace(/@.+$/, '')
        .trim();
}

// ==================== HELPERS ====================
function normalizeText(text) {
    return (text || '').trim().toLowerCase();
}

function detectLanguage(text) {
    const normalized = (text || '').toLowerCase();

    const indonesianWords = [
        'halo','hallo','hai','hi','pagi','siang','sore','malam',
        'assalamualaikum','selamat','terima kasih','makasih',
        'tolong','bagaimana','cara','apa','saya','anda','bisa'
    ];

    const englishWords = [
        'hello','hi','hey','thank you','thanks','please',
        'how','what','can you','help','support'
    ];

    let idScore = 0;
    let enScore = 0;

    for (const word of indonesianWords) {
        if (normalized.includes(word)) idScore++;
    }

    for (const word of englishWords) {
        if (normalized.includes(word)) enScore++;
    }

    return idScore >= enScore ? 'id' : 'en';
}

function isGreetingLike(text) {
    const normalized = normalizeText(text);

    const greetings = [
        'halo','hallo','hai','hi','hello','hy','hey',
        'pagi','siang','sore','malam','permisi',
        'assalamualaikum','assalamu alaikum',
        'selamat pagi','selamat siang','selamat sore','selamat malam',
        'salam'
    ];

    return greetings.some(word =>
        normalized === word ||
        normalized.startsWith(word + ' ')
    );
}

function isDirectAdminRequest(text) {
    const normalized = normalizeText(text);

    const admins = [
        'admin','cs','customer service','customer support','support',
        'tim support','operator','human','orang','staff','pegawai',
        'teknisi','hubungi admin','hubungi cs','hubungi support',
        'bicara admin','bicara cs','bicara support','chat admin',
        'chat dengan admin','contact admin','contact support',
        'talk to admin','live agent','agent'
    ];

    return admins.some(word => normalized.includes(word));
}

function getGreetingReply(text) {
    const isId = detectLanguage(text) === 'id';

    return isId
        ? `Halo 👋

Selamat datang di *Customer Service Eyre Hypercon*.

Ada yang bisa kami bantu hari ini?`
        : `Hello 👋

Welcome to *Eyre Hypercon Customer Service*.

How can we help you today?`;
}

function getPhoneFromJid(jid, msg) {
    if (!jid) return '';

    const raw = String(jid)
        .split('@')[0]
        .split(':')[0]
        .trim();

    const participant = String(msg?.key?.participant || '')
        .split('@')[0]
        .split(':')[0]
        .trim();

    const candidates = [participant, raw].filter(Boolean);

    for (const candidate of candidates) {
        if (!/^\\d+$/.test(candidate)) continue;

        if (candidate.length >= 10 && candidate.length <= 15) {
            return candidate;
        }
    }

    return raw || '';
}

function isEndSessionText(text) {
    const normalized = normalizeText(text);

    const endKeywords = [
        'akhiri',
        'akhiri sesi',
        'end',
        'done',
        'selesai',
        'finish',
        'close'
    ];

    return endKeywords.some(keyword => normalized.includes(keyword));
}
// ==================== STATE ====================
function getCustomerState(phone) {
    if (!customerStates.has(phone)) {
        customerStates.set(phone, {
            menuShown: false,
            escalationMenuShown: false,
            escalatedToAdmin: false,
            botPaused: false,
            sessionJid: ''
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
    state.sessionJid = '';
}

function getSessionClosedReply() {
    return `Terima kasih atas pertanyaannya.

Semoga informasi yang diberikan membantu.

✅ Bot kembali aktif dan siap membantu Anda.`;
}

// ==================== FALLBACK ====================
async function sendFallbackReply(sock, jid, text) {
    const isId = detectLanguage(text) === 'id';

    const fallback = isId
        ? `Maaf, sistem sedang mengalami gangguan sementara.

Saya belum bisa memproses pesan Anda saat ini.`
        : `Sorry, the system is temporarily unavailable.

I cannot process your message right now.`;

    await sock.sendMessage(jid, { text: fallback }).catch(() => {});
}

// ==================== ESCALATION ====================
function shouldOfferEscalationMenu(text, responseData) {
    if (!responseData) return false;

    const normalizedText = normalizeText(text);

    if (!normalizedText) return false;
    if (isGreetingLike(text) || isDirectAdminRequest(text)) return false;

    const shouldEscalate = Boolean(responseData?.should_escalate);
    const confidence = Number(responseData?.confidence || 0);
    const replyText = String(responseData?.reply || '');

    const weakSignal = /maaf|sorry|tidak tersedia|not available|tidak dapat|not sure|belum yakin|gangguan|sistem/i.test(replyText);

    return shouldEscalate || confidence < 50 || (weakSignal && confidence < 80);
}

function shouldShowEscalationMenu(text, phone) {
    const state = getCustomerState(phone);

    if (state.escalationMenuShown) return false;
    if (isGreetingLike(text)) return false;
    if (isDirectAdminRequest(text)) return false;

    return true;
}

async function sendEscalationMenu(sock, jid, phone, text) {
    const state = getCustomerState(phone);

    if (state.escalationMenuShown) return;

    const isId = detectLanguage(text) === 'id';

    const prompt = isId
        ? `Saya belum yakin bisa membantu sepenuhnya.

Silakan pilih salah satu opsi berikut:

1️⃣ Chat Admin
2️⃣ Kirim Email ke *eyre.hypercon@gmail.com*

Balas *1* untuk chat admin atau *2* untuk kirim email.`
        : `I’m not fully sure I can help with this yet.

Please choose one of the following options:

1️⃣ Chat Admin
2️⃣ Send Email to *eyre.hypercon@gmail.com*

Reply *1* for admin chat or *2* for email.`;

    state.escalationMenuShown = true;

    await sock.sendMessage(jid, { text: prompt }).catch(() => {});
}

async function escalateToAdmin(sock, jid, phone, text, source = 'auto') {
    const state = getCustomerState(phone);
    const isId = detectLanguage(text) === 'id';

    // aktifkan mode admin
    state.escalatedToAdmin = true;
    state.botPaused = true;
    state.sessionJid = jid;

    const reply = isId
        ? `Anda sekarang terhubung dengan *Admin Eyre Hypercon*.

Silakan sampaikan kebutuhan atau kendala Anda, dan tim kami akan membantu secepatnya.

🛑 Selama sesi admin berlangsung, bot tidak akan memberikan balasan otomatis.

Admin akan mengetik *akhiri* jika sesi sudah selesai.`
        : `You are now connected with *Eyre Hypercon Admin*.

Please describe your issue, and our team will assist you as soon as possible.

🛑 During the admin session, the bot will not send automatic replies.

The admin will type *end* when the session is finished.`;

    await sock.sendMessage(jid, { text: reply }).catch(() => {});
}

// ==================== END SESSION ====================
async function tryEndAdminSession(sock, jid, phone, text, fromMe) {

    // hanya pesan dari device sendiri (admin)
    if (!fromMe) return false;

    // ambil nomor WA bot sendiri
    const ownNumber = getOwnNumber(sock);

    // pastikan nomor bot termasuk admin
    if (!isAdminNumber(ownNumber)) {
        console.log('❌ Own number is not registered as admin:', ownNumber);
        return false;
    }

    const normalized = normalizeText(text);

    // cek kata penutup sesi
    if (!isEndSessionText(normalized)) {
        return false;
    }

    // cari customer yang sedang ditangani admin
    let targetPhone = null;
    let targetState = null;

    for (const [customerPhone, state] of customerStates.entries()) {

        if (
            state.escalatedToAdmin &&
            state.botPaused &&
            normalizeText(state.sessionJid || '') === normalizeText(jid || '')
        ) {
            targetPhone = customerPhone;
            targetState = state;
            break;
        }
    }

    if (!targetState) {
        console.log('⚠ Tidak ada sesi admin yang aktif');
        return false;
    }

    // aktifkan kembali bot
    targetState.botPaused = false;
    targetState.escalatedToAdmin = false;
    targetState.sessionJid = '';
    targetState.escalationMenuShown = false;
    targetState.menuShown = false;

    await sock.sendMessage(jid, {
        text: getSessionClosedReply()
    }).catch(() => {});

    console.log(`🔓 Session customer ${targetPhone} diakhiri oleh admin ${ownNumber}`);

    return true;
}

// ==================== BUTTON / MENU HANDLER ====================
async function handleButtonChoice(sock, jid, phone, text, fromMe) {

    const normalized = normalizeText(text);
    const state = getCustomerState(phone);

    // admin bisa mengakhiri sesi
    if (await tryEndAdminSession(sock, jid, phone, text, fromMe)) {
        return true;
    }

    // pilihan chat admin
    const isAdminChoice =
        state.escalationMenuShown &&
        (
            normalized === '1' ||
            normalized === 'chat admin' ||
            normalized === 'hubungi admin' ||
            normalized === 'escalate_admin'
        );

    if (isAdminChoice) {
        state.escalationMenuShown = false;

        await escalateToAdmin(sock, jid, phone, text, 'admin_choice');
        return true;
    }

    // pilihan email
    const isEmailChoice =
        state.escalationMenuShown &&
        (
            normalized === '2' ||
            normalized === 'email' ||
            normalized === 'kirim email' ||
            normalized === 'send email' ||
            normalized === 'escalate_email'
        );

    if (isEmailChoice) {
        state.escalationMenuShown = false;

        const isId = detectLanguage(text) === 'id';

        await sock.sendMessage(jid, {
            text: isId
                ? `Silakan kirim email ke:

📧 *eyre.hypercon@gmail.com*

Tim kami akan merespons secepat mungkin.`
                : `Please send an email to:

📧 *eyre.hypercon@gmail.com*

Our team will respond as soon as possible.`
        }).catch(() => {});

        return true;
    }

    return false;
}
async function startBot() {

    const { state, saveCreds } =
        await useMultiFileAuthState('session');

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'silent' }),
        printQRInTerminal: false,
        syncFullHistory: false,
        markOnlineOnConnect: false
    });

    sock.ev.on('creds.update', saveCreds);

    // ==================== CONNECTION ====================
    sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {

        if (qr) {
            console.clear();
            console.log('Scan QR berikut:\\n');
            QRCode.generate(qr, { small: true });
        }

        if (connection === 'connecting') {
            console.log('🟡 Connecting...');
        }

        if (connection === 'open') {
            console.log('✅ WhatsApp Connected');
            console.log('📱 Own number:', getOwnNumber(sock));
            console.log('👮 Admin list:', ADMIN_NUMBERS);
        }

        if (connection === 'close') {

            const reason =
                lastDisconnect?.error instanceof Boom
                    ? lastDisconnect.error.output.statusCode
                    : 0;

            console.log('❌ Disconnected:', reason);

            if (reason !== DisconnectReason.loggedOut) {

                console.log('🔄 Reconnecting...');

                setTimeout(() => {
                    startBot();
                }, 3000);

            } else {

                console.log('⚠ Session logout, scan QR lagi.');

            }
        }
    });

    // ==================== MESSAGE HANDLER ====================
    sock.ev.on('messages.upsert', async (event) => {

        try {

            const msg = event.messages?.[0];

            if (!msg || !msg.message) return;

            const fromMe = Boolean(msg.key?.fromMe);
            const jid = msg.key.remoteJid;

            if (!jid) return;

            // skip group & broadcast
            if (jid.endsWith('@g.us') || jid.endsWith('@broadcast')) {
                return;
            }

            const phone = getPhoneFromJid(jid, msg);
            const name = msg.pushName || 'Customer';

            // ambil isi pesan
            let text = '';

            if (msg.message.conversation) {
                text = msg.message.conversation;
            }
            else if (msg.message.extendedTextMessage) {
                text = msg.message.extendedTextMessage.text;
            }
            else if (msg.message.imageMessage) {
                text = msg.message.imageMessage.caption || '';
            }
            else if (msg.message.videoMessage) {
                text = msg.message.videoMessage.caption || '';
            }
            else if (msg.message.buttonsResponseMessage) {
                text = msg.message.buttonsResponseMessage.selectedButtonId;
            }
            else if (msg.message.listResponseMessage) {
                text =
                    msg.message.listResponseMessage
                        .singleSelectReply
                        ?.selectedRowId || '';
            }

            text = text.trim();

            if (!text) return;

            console.log('\\n==============================');
            console.log('📩 Pesan Masuk');
            console.log('Nama  :', name);
            console.log('Phone :', phone);
            console.log('Pesan :', text);
            console.log('==============================');

            const state = getCustomerState(phone);

            // greeting reset state
            if (isGreetingLike(text)) {
                resetCustomerState(phone);
            }

            // admin mengakhiri sesi
            const endedSession = await tryEndAdminSession(
                sock,
                jid,
                phone,
                text,
                fromMe
            );

            if (endedSession) {
                return;
            }

            // pesan dari admin / bot sendiri jangan diproses
            if (fromMe) {
                return;
            }

            // MODE ADMIN = BOT DIAM TOTAL
            if (state.botPaused) {
                console.log(`🤫 Bot paused for ${phone}`);
                return;
            }

            // cek pilihan menu
            const buttonHandled = await handleButtonChoice(
                sock,
                jid,
                phone,
                text,
                fromMe
            );

            if (buttonHandled) {
                return;
            }

            await sock.sendPresenceUpdate('composing', jid).catch(() => {});

            // greeting
            if (isGreetingLike(text)) {
                await sock.sendMessage(jid, {
                    text: getGreetingReply(text)
                }).catch(() => {});
                return;
            }

            // minta admin langsung
            if (isDirectAdminRequest(text)) {
                await escalateToAdmin(sock, jid, phone, text, 'admin_request');
                return;
            }

            // ==================== CALL LARAVEL ====================
            let response;

            try {

                response = await axios.post(
                    'http://127.0.0.1:8000/api/chat',
                    {
                        phone,
                        name,
                        message: text
                    },
                    {
                        timeout: 15000
                    }
                );

            } catch (err) {

                console.log('\\n===== BACKEND TIMEOUT / ERROR =====');
                console.log(err?.message || err);

                await sock.sendPresenceUpdate('paused', jid).catch(() => {});

                await sendFallbackReply(sock, jid, text);
                await sendEscalationMenu(sock, jid, phone, text);

                return;
            }

            console.log('\\nLaravel Reply:');
            console.log(response.data.reply);

            await sock.sendPresenceUpdate('paused', jid).catch(() => {});

            const botReply =
                response?.data?.reply ||
                'Maaf, saya belum bisa memproses permintaan Anda saat ini.';

            await sock.sendMessage(jid, {
                text: botReply
            });

            // tawarkan admin jika confidence rendah
            const shouldEscalate =
                shouldOfferEscalationMenu(text, response?.data);

            if (
                shouldEscalate &&
                shouldShowEscalationMenu(text, phone)
            ) {
                await sendEscalationMenu(sock, jid, phone, text);
            }

            console.log('✅ Reply berhasil dikirim');

        } catch (err) {

            console.log('\\n===== ERROR =====');

            if (err.response) {
                console.log(err.response.data);
            } else {
                console.log(err);
            }
        }
    });
}

// ==================== START ====================
startBot();
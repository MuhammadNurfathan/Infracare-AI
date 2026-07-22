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
    .map(n => n.trim().replace(/[^0-9]/g, ''))
    .filter(Boolean);

function isAdminNumber(phone) {
    if (!phone) return false;
    const cleanPhone = String(phone).replace(/[^0-9]/g, '');
    return ADMIN_NUMBERS.includes(cleanPhone);
}

function getOwnNumber(sock) {
    return String(sock.user?.id || '')
        .split(':')[0]
        .replace(/[^0-9]/g, '')
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
        ? `Halo 👋\n\nSelamat datang di *Customer Service Eyre Hypercon*.\n\nAda yang bisa kami bantu hari ini?`
        : `Hello 👋\n\nWelcome to *Eyre Hypercon Customer Service*.\n\nHow can we help you today?`;
}

function getPhoneFromJid(jid, msg) {
    if (!jid) return '';

    const raw = String(jid)
        .split('@')[0]
        .split(':')[0]
        .replace(/[^0-9]/g, '')
        .trim();

    const participant = String(msg?.key?.participant || '')
        .split('@')[0]
        .split(':')[0]
        .replace(/[^0-9]/g, '')
        .trim();

    const candidates = [participant, raw].filter(Boolean);

    for (const candidate of candidates) {
        if (candidate.length >= 10 && candidate.length <= 15) {
            return candidate;
        }
    }

    return raw || '';
}

// FIX: Pengecekan penutup sesi harus EXACT MATCH (tidak boleh includes)
function isEndSessionText(text) {
    const normalized = normalizeText(text);

    const exactEndKeywords = [
        'akhiri',
        'akhiri sesi',
        'end',
        'end session',
        'close',
        'close session',
        'selesai',
        'finish'
    ];

    return exactEndKeywords.includes(normalized);
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
    return `Terima kasih atas pertanyaannya.\n\nSemoga informasi yang diberikan membantu.\n\n✅ Sesi admin telah diakhiri. Bot kembali aktif untuk membantu Anda.`;
}

function getWaitingReply(text) {
    const isId = detectLanguage(text) === 'id';

    return isId
        ? `Mohon tunggu sebentar, jawaban dari pertanyaan Anda sedang dicari...`
        : `Please wait a moment, your answer is being searched...`;
}

// ==================== FALLBACK ====================
async function sendFallbackReply(sock, jid, text) {
    const isId = detectLanguage(text) === 'id';

    const fallback = isId
        ? `Maaf, sistem sedang mengalami gangguan sementara.\n\nSaya belum bisa memproses pesan Anda saat ini.`
        : `Sorry, the system is temporarily unavailable.\n\nI cannot process your message right now.`;

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

async function escalateToAdmin(sock, jid, phone, text, source = 'auto') {
    const state = getCustomerState(phone);
    const isId = detectLanguage(text) === 'id';

    // Aktifkan mode admin
    state.escalatedToAdmin = true;
    state.botPaused = true;
    state.sessionJid = jid;

    const reply = isId
        ? `Anda sekarang terhubung dengan *Admin Eyre Hypercon*.\n\nSilakan sampaikan kebutuhan atau kendala Anda, dan tim kami akan membantu secepatnya.\n\n🛑 Selama sesi admin berlangsung, bot tidak akan memberikan balasan otomatis.\n\nAdmin akan mengetik *akhiri* jika sesi sudah selesai.`
        : `You are now connected with *Eyre Hypercon Admin*.\n\nPlease describe your issue, and our team will assist you as soon as possible.\n\n🛑 During the admin session, the bot will not send automatic replies.\n\nThe admin will type *end* when the session is finished.`;

    await sock.sendMessage(jid, { text: reply }).catch(() => {});
}

// ==================== END SESSION ====================
async function tryEndAdminSession(sock, jid, customerPhone, text, msg) {
    const normalized = normalizeText(text);

    // 1. Cek apakah teks adalah kata penutup yang valid
    if (!isEndSessionText(normalized)) {
        return false;
    }

    const state = getCustomerState(customerPhone);

    // 2. Pastikan pelanggan memang sedang dalam mode admin
    if (!state.escalatedToAdmin || !state.botPaused) {
        return false;
    }

    // 3. Hanya PERINTAH DARI ADMIN / DEVICE BOT yang boleh mengakhiri sesi
    const fromMe = Boolean(msg.key?.fromMe);
    const senderPhone = getPhoneFromJid(msg.key?.participant || jid, msg);
    const ownNumber = getOwnNumber(sock);

    const isSenderAdmin = fromMe || isAdminNumber(senderPhone) || isAdminNumber(ownNumber);

    if (!isSenderAdmin) {
        return false;
    }

    // Reset State Pelanggan
    resetCustomerState(customerPhone);

    await sock.sendMessage(jid, {
        text: getSessionClosedReply()
    }).catch(() => {});

    console.log(`🔓 Sesi pelanggan ${customerPhone} diakhiri oleh Admin (${senderPhone || ownNumber})`);

    return true;
}

// ==================== BUTTON / MENU HANDLER ====================
async function handleButtonChoice(sock, jid, phone, text) {
    const normalized = normalizeText(text);
    const state = getCustomerState(phone);

    // PILIH CHAT ADMIN
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
        console.log(`👨‍💻 Customer ${phone} masuk mode admin`);
        return true;
    }

    // PILIH EMAIL
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
                ? `Silakan kirim email ke:\n\n📧 *eyrehypercon@gmail.com*\n\nTim kami akan merespons secepat mungkin.`
                : `Please send an email to:\n\n📧 *eyrehypercon@gmail.com*\n\nOur team will respond as soon as possible.`
        }).catch(() => {});

        return true;
    }

    return false;
}

// ==================== MAIN BOT ====================
async function startBot() {
    const { state, saveCreds } = await useMultiFileAuthState('session');

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: 'silent' }),
        printQRInTerminal: false,
        syncFullHistory: false,
        markOnlineOnConnect: false
    });

    sock.ev.on('creds.update', saveCreds);

    // CONNECTION
    sock.ev.on('connection.update', ({ connection, lastDisconnect, qr }) => {
        if (qr) {
            console.clear();
            console.log('Scan QR berikut:\n');
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
            const reason = lastDisconnect?.error instanceof Boom
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

    // MESSAGE HANDLER
    sock.ev.on('messages.upsert', async (event) => {
        try {
            const msg = event.messages?.[0];

            if (!msg || !msg.message) return;

            const fromMe = Boolean(msg.key?.fromMe);
            const jid = msg.key.remoteJid;

            if (!jid) return;

            // Skip grup & broadcast
            if (jid.endsWith('@g.us') || jid.endsWith('@broadcast')) {
                return;
            }

            const phone = getPhoneFromJid(jid, msg);
            const name = msg.pushName || 'Customer';

            // Ambil teks pesan
            let text = '';
            if (msg.message.conversation) {
                text = msg.message.conversation;
            } else if (msg.message.extendedTextMessage) {
                text = msg.message.extendedTextMessage.text;
            } else if (msg.message.imageMessage) {
                text = msg.message.imageMessage.caption || '';
            } else if (msg.message.videoMessage) {
                text = msg.message.videoMessage.caption || '';
            } else if (msg.message.buttonsResponseMessage) {
                text = msg.message.buttonsResponseMessage.selectedButtonId;
            } else if (msg.message.listResponseMessage) {
                text = msg.message.listResponseMessage.singleSelectReply?.selectedRowId || '';
            }

            text = text.trim();
            if (!text) return;

            console.log('\n==============================');
            console.log('📩 Pesan Masuk');
            console.log('Nama  :', name);
            console.log('Phone :', phone);
            console.log('Pesan :', text);
            console.log('==============================');

            // Cek jika ini instruksi penutupan sesi dari Admin
            const endedSession = await tryEndAdminSession(sock, jid, phone, text, msg);
            if (endedSession) {
                return;
            }

            const state = getCustomerState(phone);

            // Jika dalam mode admin -> Bot diam total
            if (state.botPaused) {
                console.log(`🤫 Bot paused untuk ${phone} (Sesi Admin Aktif)`);
                return;
            }

            // Abaikan pesan yang dikirim oleh device sendiri (kecuali instruksi penutup di atas)
            if (fromMe) {
                return;
            }

            // Reset state jika greeting
            if (isGreetingLike(text)) {
                resetCustomerState(phone);
            }

            // Cek pilihan menu (1 / 2)
            const buttonHandled = await handleButtonChoice(sock, jid, phone, text);
            if (buttonHandled) {
                return;
            }

            // Respon Greeting
            if (isGreetingLike(text)) {
                await sock.sendPresenceUpdate('composing', jid).catch(() => {});
                await sock.sendMessage(jid, {
                    text: getGreetingReply(text)
                }).catch(() => {});
                await sock.sendPresenceUpdate('paused', jid).catch(() => {});
                return;
            }

            // Minta admin langsung
            if (isDirectAdminRequest(text)) {
                await escalateToAdmin(sock, jid, phone, text, 'admin_request');
                return;
            }

            await sock.sendPresenceUpdate('composing', jid).catch(() => {});
            await sock.sendMessage(jid, {
                text: getWaitingReply(text)
            }).catch(() => {});

            // CALL API LARAVEL
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
                        timeout: 360000
                    }
                );
            } catch (err) {
                console.log('\n===== BACKEND TIMEOUT / ERROR =====');
                console.log(err?.message || err);

                await sendFallbackReply(sock, jid, text);
                await sock.sendPresenceUpdate('paused', jid).catch(() => {});
                return;
            }

            console.log('\nLaravel Reply:');
            console.log(response.data.reply);

            const botReply = response?.data?.reply || 'Maaf, saya belum bisa memproses permintaan Anda saat ini.';

            // Kirim balasan dari Laravel
            await sock.sendMessage(jid, { text: botReply });
            await sock.sendPresenceUpdate('paused', jid).catch(() => {});

            // FIX: Cukup tandai state TANPA mengirim pesan menu kedua kali secara otomatis
            if (shouldOfferEscalationMenu(text, response?.data)) {
                state.escalationMenuShown = true;
            }

            console.log('✅ Reply berhasil dikirim');

        } catch (err) {
            console.log('\n===== ERROR =====');
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
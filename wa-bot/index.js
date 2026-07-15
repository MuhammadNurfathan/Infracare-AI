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

async function startBot() {

    const { state, saveCreds } = await useMultiFileAuthState("session");

    const sock = makeWASocket({
        auth: state,
        logger: P({ level: "silent" }),
        printQRInTerminal: false
    });

    sock.ev.on("creds.update", saveCreds);

    sock.ev.on("connection.update", ({ connection, lastDisconnect, qr }) => {

        if (qr) {
            QRCode.generate(qr, {
                small: true
            });
        }

        if (connection === "open") {
            console.log("✅ WhatsApp Connected");
        }

        if (connection === "close") {

            console.log("❌ WhatsApp Disconnected");

            const shouldReconnect =
                lastDisconnect?.error instanceof Boom
                    ? lastDisconnect.error.output.statusCode !== DisconnectReason.loggedOut
                    : true;

            if (shouldReconnect) {
                console.log("🔄 Reconnecting...");
                startBot();
            }

        }

    });

    sock.ev.on("messages.upsert", async ({ messages, type }) => {

        if (type !== "notify" && type !== "append") return;

        const msg = messages[0];

        if (!msg) return;
        if (!msg.message) return;
        if (msg.key.fromMe) return;

        const jid =
            msg.key.remoteJidAlt ||
            msg.key.remoteJid;

        if (!jid) return;

        const phone = jid
            .replace("@s.whatsapp.net", "")
            .replace("@lid", "");

        const name = msg.pushName || "Customer";

        const text =
            msg.message.conversation ||
            msg.message.extendedTextMessage?.text ||
            msg.message.imageMessage?.caption ||
            msg.message.videoMessage?.caption;

        if (!text) return;

        console.log("================================");
        console.log("📩 Pesan Masuk");
        console.log("Nama   :", name);
        console.log("Phone  :", phone);
        console.log("Pesan  :", text);
        console.log("================================");

        try {

            const response = await axios.post(
                "http://127.0.0.1:8000/api/chat",
                {
                    phone,
                    name,
                    message: text
                }
            );

            console.log("Laravel Reply:");
            console.log(response.data);

            await sock.sendMessage(jid, {
                text: response.data.reply
            });

        } catch (err) {

            console.log("===== ERROR =====");

            if (err.response) {
                console.log(err.response.data);
            } else {
                console.log(err.message);
            }

            await sock.sendMessage(jid, {
                text: "Mohon maaf, server sedang mengalami gangguan."
            });

        }

    });

}

startBot();
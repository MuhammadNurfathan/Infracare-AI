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
        logger: P({ level: "silent" })
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

            const shouldReconnect =
                (lastDisconnect?.error instanceof Boom)
                    ? lastDisconnect.error.output.statusCode !== DisconnectReason.loggedOut
                    : true;

            if (shouldReconnect) {
                startBot();
            }
        }

    });

    sock.ev.on("messages.upsert", async ({ messages }) => {

        const msg = messages[0];

        if (!msg.message) return;

        if (msg.key.fromMe) return;

        const jid = msg.key.remoteJid;

        const text =
            msg.message.conversation ||
            msg.message.extendedTextMessage?.text;

        if (!text) return;

        console.log("Pesan :", text);

        try {

            const response = await axios.post(
                "http://127.0.0.1:8000/api/chat",
                {
                    message: text
                }
            );

            await sock.sendMessage(jid, {
                text: response.data.reply
            });

        } catch (err) {

            console.log(err.message);

            await sock.sendMessage(jid, {
                text: "Maaf, server sedang bermasalah."
            });

        }

    });

}

startBot();
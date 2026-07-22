require('dotenv').config();

const {
    default: makeWASocket,
    useMultiFileAuthState,
    DisconnectReason
} = require('@whiskeysockets/baileys');

const {
    Boom
} = require('@hapi/boom');

const P = require('pino');
const QRCode = require('qrcode-terminal');
const axios = require('axios');
const express = require('express');


// ==================================================
// GLOBAL
// ==================================================

let sockInstance = null;
let reconnecting = false;

const customerStates = new Map();
const processedMessages = new Set();


// ==================================================
// ADMIN CONFIG
// ==================================================

const ADMIN_NUMBERS = (process.env.ADMIN_NUMBERS || '')
    .split(',')
    .map(n =>
        n.replace(/[^0-9]/g, '')
    )
    .filter(Boolean);


function isAdminNumber(phone) {

    if (!phone) return false;

    const clean =
        String(phone)
            .replace(/[^0-9]/g,'');

    return ADMIN_NUMBERS.includes(clean);
}


function getOwnNumber(sock){

    return String(sock.user?.id || '')
        .split(':')[0]
        .replace(/[^0-9]/g,'');
}



// ==================================================
// BASIC HELPERS
// ==================================================

function normalizeText(text){

    return String(text || '')
        .trim()
        .toLowerCase();

}



function detectLanguage(text){

    const t = normalizeText(text);


    const idWords = [
        'halo',
        'hai',
        'tolong',
        'cara',
        'bagaimana',
        'apa',
        'saya',
        'bisa',
        'terima kasih'
    ];


    const enWords = [
        'hello',
        'help',
        'how',
        'what',
        'please',
        'thank'
    ];


    let id = 0;
    let en = 0;


    idWords.forEach(w=>{
        if(t.includes(w)) id++;
    });


    enWords.forEach(w=>{
        if(t.includes(w)) en++;
    });


    return id >= en ? 'id':'en';
}



function getPhoneFromJid(jid,msg){

    if(!jid) return '';

    const participant =
        msg?.key?.participant || '';


    const numbers=[
        jid,
        participant
    ];


    for(const n of numbers){

        const phone =
            String(n)
            .split('@')[0]
            .split(':')[0]
            .replace(/[^0-9]/g,'');


        if(phone.length >=10){
            return phone;
        }
    }


    return '';

}



// ==================================================
// MESSAGE TYPE
// ==================================================

function extractMessageText(msg){

    if(!msg.message)
        return '';


    if(msg.message.conversation)
        return msg.message.conversation;


    if(msg.message.extendedTextMessage)
        return msg.message.extendedTextMessage.text;


    if(msg.message.imageMessage)
        return msg.message.imageMessage.caption || '';


    if(msg.message.videoMessage)
        return msg.message.videoMessage.caption || '';


    if(msg.message.buttonsResponseMessage)
        return msg.message.buttonsResponseMessage.selectedButtonId;


    if(msg.message.listResponseMessage)
        return (
            msg.message
            .listResponseMessage
            .singleSelectReply
            ?.selectedRowId || ''
        );


    return '';
}



// ==================================================
// STATE
// ==================================================

function getCustomerState(phone){

    if(!customerStates.has(phone)){

        customerStates.set(phone,{
            escalatedToAdmin:false,
            botPaused:false,
            escalationMenuShown:false,
            sessionJid:''
        });

    }


    return customerStates.get(phone);

}



function resetCustomerState(phone){

    customerStates.set(phone,{
        escalatedToAdmin:false,
        botPaused:false,
        escalationMenuShown:false,
        sessionJid:''
    });

}



// ==================================================
// GREETING
// ==================================================

function isGreetingLike(text){

    const t=normalizeText(text);


    const list=[
        'halo',
        'hai',
        'hello',
        'hi',
        'pagi',
        'siang',
        'sore',
        'malam',
        'assalamualaikum'
    ];


    return list.some(x =>
        t===x ||
        t.startsWith(x+' ')
    );

}



function greetingReply(text){

    return detectLanguage(text)==='id'

        ?
`Halo 👋

Selamat datang di *Customer Service Eyre Hypercon*.

Ada yang bisa kami bantu hari ini?`

        :
`Hello 👋

Welcome to *Eyre Hypercon Customer Service*.

How can we help you today?`;

}



// ==================================================
// ADMIN REQUEST
// ==================================================

function isDirectAdminRequest(text){

    const t=normalizeText(text);


    const words=[
        'admin',
        'cs',
        'customer service',
        'support',
        'operator',
        'human',
        'teknisi',
        'bicara admin',
        'hubungi admin'
    ];


    return words.some(x=>t.includes(x));

}



// ==================================================
// SEND SAFE
// ==================================================

async function safeSend(jid,text){

    console.log('📤 Sending WhatsApp');
    console.log('Target:', jid);
    console.log('Message:', text);


    if(!sockInstance){

        console.log(
            '❌ WhatsApp socket belum aktif'
        );

        return;
    }


    try{

        const result =
        await sockInstance.sendMessage(
            jid,
            {
                text
            }
        );


        console.log(
            '✅ WhatsApp sent',
            result.key.id
        );


    }catch(err){

        console.log(
            '❌ SEND ERROR:',
            err
        );

    }

}



// ==================================================
// ALERT API
// ==================================================

function startAlertServer(){


    const app=express();


    app.use(
        express.json()
    );


    const ALERT_TARGET =
        process.env.ALERT_TARGET ||
        '';



    app.post('/send-alert',async(req,res)=>{


        try{


            const {
                message
            } = req.body;



            await safeSend(
                ALERT_TARGET,
                message
            );


            console.log(
                '🚨 Alert sent'
            );


            res.json({
                success:true
            });



        }catch(err){


            console.log(err);


            res.status(500)
            .json({
                success:false
            });

        }


    });



    app.listen(3000,()=>{

        console.log(
            '🚨 Alert API running port 3000'
        );

    });


}

// ==================================================
// SESSION END
// ==================================================

function isEndSessionText(text){

    const t = normalizeText(text);

    return [
        'akhiri',
        'akhiri sesi',
        'end',
        'end session',
        'close',
        'close session',
        'selesai',
        'finish'
    ].includes(t);

}



async function tryEndAdminSession(
    jid,
    customerPhone,
    text,
    msg
){


    if(!isEndSessionText(text))
        return false;



    const state =
        getCustomerState(customerPhone);



    if(
        !state.botPaused ||
        !state.escalatedToAdmin
    ){
        return false;
    }



    const sender =
        getPhoneFromJid(
            msg.key.participant || jid,
            msg
        );


    const fromMe =
        Boolean(msg.key.fromMe);



    const isAdmin =
        fromMe ||
        isAdminNumber(sender);



    if(!isAdmin)
        return false;



    resetCustomerState(customerPhone);



    await safeSend(
        jid,
`Terima kasih atas informasinya.

Sesi admin telah selesai.

✅ Bot kembali aktif untuk membantu Anda.`
    );


    console.log(
        `🔓 Session ${customerPhone} closed by admin`
    );


    return true;

}



// ==================================================
// ESCALATE ADMIN
// ==================================================

async function escalateToAdmin(
    jid,
    phone
){


    const state =
        getCustomerState(phone);


    state.escalatedToAdmin=true;
    state.botPaused=true;
    state.sessionJid=jid;



    await safeSend(
        jid,
`Anda sekarang terhubung dengan
*Admin Eyre Hypercon*.

Silakan jelaskan kebutuhan atau kendala Anda.

🛑 Bot otomatis dinonaktifkan sementara.

Admin akan mengetik *akhiri* jika sesi selesai.`
    );


    console.log(
        `👨‍💻 ${phone} masuk admin mode`
    );


}



// ==================================================
// ESCALATION CHECK
// ==================================================

function shouldEscalate(data){


    if(!data)
        return false;


    const confidence =
        Number(
            data.confidence || 0
        );


    const reply =
        String(
            data.reply || ''
        )
        .toLowerCase();



    return (
        data.should_escalate === true ||
        confidence < 50 ||
        reply.includes('maaf') ||
        reply.includes('tidak dapat')
    );

}



// ==================================================
// START BOT
// ==================================================

async function startBot(){


    const {
        state,
        saveCreds
    } =
    await useMultiFileAuthState(
        'session'
    );



    const sock =
    makeWASocket({

        auth:state,

        logger:P({
            level:'silent'
        }),

        printQRInTerminal:false,

        syncFullHistory:false,

        markOnlineOnConnect:false

    });



    sockInstance=sock;



    sock.ev.on(
        'creds.update',
        saveCreds
    );



    sock.ev.on(
    'connection.update',
    async(update)=>{


        const {
            connection,
            qr,
            lastDisconnect
        }=update;



        if(qr){

            console.clear();

            console.log(
                'Scan QR WhatsApp'
            );

            QRCode.generate(
                qr,
                {
                    small:true
                }
            );

        }



        if(connection==='open'){


            reconnecting=false;


            console.log(
                '✅ WhatsApp Connected'
            );


            console.log(
                '📱 Number:',
                getOwnNumber(sock)
            );


            console.log(
                '👮 Admin:',
                ADMIN_NUMBERS
            );

        }




        if(connection==='close'){



            const reason =
            lastDisconnect?.error instanceof Boom
            ?
            lastDisconnect.error.output.statusCode
            :
            0;



            console.log(
                'Disconnected:',
                reason
            );



            if(
                reason !== DisconnectReason.loggedOut
                &&
                !reconnecting
            ){


                reconnecting=true;


                console.log(
                    '🔄 reconnecting...'
                );



                setTimeout(
                    startBot,
                    5000
                );

            }


        }


    });





// ==================================================
// MESSAGE LISTENER
// ==================================================

sock.ev.on(
'messages.upsert',
async(event)=>{


try{


const msg =
event.messages?.[0];



if(!msg?.message)
    return;



const msgId =
msg.key.id;



if(processedMessages.has(msgId))
    return;



processedMessages.add(msgId);



setTimeout(()=>{

    processedMessages.delete(msgId);

},60000);





const jid =
msg.key.remoteJid;



if(!jid)
    return;



if(
    jid.endsWith('@g.us') ||
    jid.endsWith('@broadcast')
)
return;



const phone =
getPhoneFromJid(
    jid,
    msg
);



const name =
msg.pushName ||
'Customer';



const text =
extractMessageText(msg)
.trim();



if(!text)
    return;




console.log(`
==============================
📩 MESSAGE
Name : ${name}
Phone: ${phone}
Text : ${text}
==============================
`);





// END SESSION
if(
await tryEndAdminSession(
    jid,
    phone,
    text,
    msg
))
return;





const state =
getCustomerState(phone);





// BOT PAUSE ADMIN

if(state.botPaused){


    console.log(
        '🤫 Bot paused',
        phone
    );


    return;

}






// IGNORE SELF MESSAGE

if(msg.key.fromMe)
    return;






// GREETING RESET

if(
isGreetingLike(text)
){

    resetCustomerState(phone);


    await safeSend(
        jid,
        greetingReply(text)
    );


    return;

}







// DIRECT ADMIN

if(
isDirectAdminRequest(text)
){

    await escalateToAdmin(
        jid,
        phone
    );


    return;

}






// WAIT MESSAGE

await safeSend(
jid,
'⏳ Mohon tunggu, pertanyaan Anda sedang diproses...'
);






// CALL LARAVEL


let response;


try{


response =
await axios.post(

'http://127.0.0.1:8000/api/chat',

{

phone,

name,

message:text

},

{

timeout:90000

}

);



}catch(err){


console.log(
'Laravel Error:',
err.message
);


await safeSend(
jid,
'Maaf, sistem sedang mengalami gangguan sementara.'
);


return;

}






const data =
response.data;



const reply =
data.reply ||
'Maaf saya belum dapat menjawab.';





await safeSend(
jid,
reply
);





if(
shouldEscalate(data)
){

    state.escalationMenuShown=true;


    await safeSend(
    jid,

`Apakah Anda ingin bantuan lebih lanjut?

1️⃣ Chat Admin
2️⃣ Email`
    );

}



console.log(
'✅ Reply sent'
);



}catch(err){


console.log(
'MESSAGE ERROR',
err
);


}



});



}



// ==================================================
// START SERVICE
// ==================================================

startAlertServer();


startBot();
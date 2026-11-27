// Miniatura especial para lives de COLABORACIÓN (invitado). Uso:
//   node generar_colaboracion.js <carpeta_salida> <config.json>
// config: {num, img (relativa a esta carpeta), l1, l2, pill, chips[], izq:{n,s}, der:{n,s}, bg:[c1,c2,c3]}
const {chromium}=require('playwright');const fs=require('fs');const path=require('path');
const S=process.argv[2]||__dirname;const C=JSON.parse(fs.readFileSync(process.argv[3],'utf8'));
const html=`<!doctype html><html><head><meta charset="utf-8"><style>
*{margin:0;padding:0;box-sizing:border-box}
body{width:1280px;height:720px;overflow:hidden;font-family:'Inter Display','Inter',sans-serif;background:radial-gradient(circle at 25% 45%,${C.bg[0]} 0%,${C.bg[1]} 50%,${C.bg[2]} 100%);position:relative;color:#fff}
.foto{position:absolute;right:0;top:0;width:760px;height:604px;overflow:hidden;-webkit-mask-image:linear-gradient(to right,transparent 0%,#000 6%)}
.foto img{position:absolute;height:604px;left:-40px;top:0}
.foto:after{content:"";position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.85) 0%,rgba(0,0,0,.35) 22%,transparent 40%)}
.vivo{position:absolute;top:36px;left:48px;background:#e3192b;padding:9px 20px 9px 16px;border-radius:10px;font-weight:900;font-size:40px;letter-spacing:2px;display:flex;align-items:center;gap:12px;box-shadow:0 6px 18px rgba(0,0,0,.35)}
.vivo i{width:16px;height:16px;border-radius:50%;background:#fff;display:block}
.colab{position:absolute;top:44px;left:290px;background:#ffd21f;color:#3a2a00;font-weight:900;font-size:24px;padding:10px 16px;border-radius:10px}
.txt{position:absolute;left:44px;top:126px;width:540px}
.l1{font-weight:900;color:#ffd21f;line-height:.98;letter-spacing:-2px;text-shadow:0 5px 0 rgba(0,0,0,.45),0 10px 30px rgba(0,0,0,.6)}
.l2{font-weight:900;line-height:1.05;margin-top:16px;text-shadow:0 6px 24px rgba(0,0,0,.6)}
.pill{display:inline-block;margin-top:20px;background:#25b04f;color:#fff;font-weight:900;line-height:1;padding:10px 26px 14px;border-radius:18px;transform:rotate(-3deg);box-shadow:0 10px 30px rgba(0,0,0,.45);white-space:nowrap}
.chips{margin-top:22px;display:flex;flex-wrap:wrap;gap:10px;width:540px}.chips span{background:rgba(255,255,255,.95);color:#0b2233;font-weight:800;font-size:23px;padding:7px 14px;border-radius:999px;box-shadow:0 4px 12px rgba(0,0,0,.35)}
.n{position:absolute;top:520px;font-weight:900;font-size:24px;text-shadow:0 3px 12px rgba(0,0,0,.95);line-height:1.05}
.n span{display:block;font-weight:700;font-size:20px;color:#ffd21f}
.logo{position:absolute;right:22px;top:18px;width:250px;padding:8px 12px;background:rgba(10,24,36,.78);border-radius:12px}
.bar{position:absolute;left:0;right:0;bottom:0;height:116px;background:#0a1824;display:flex;align-items:center;padding:0 52px;gap:22px;border-top:5px solid #25b04f}
.bar svg{width:66px;height:66px;flex:none}.bar .t{font-weight:800;font-size:30px;color:#cfe3ee}.bar .nn{font-weight:900;font-size:62px;letter-spacing:1px;margin-left:auto}
</style></head><body>
<div class="foto"><img src="${C.img}"></div><img class="logo" src="logo_despacho.png">
<div class="vivo"><i></i>LIVE #${C.num}</div><div class="colab">${C.etiqueta||'COLABORACIÓN'}</div>
<div class="txt"><div class="l1" id="l1">${C.l1}</div><div class="l2" id="l2">${C.l2}</div>${C.pill?`<div class="pill" id="pill">${C.pill}</div>`:""}<div class="chips">${C.chips.map(q=>`<span>${q}</span>`).join('')}</div></div>
<div class="n" style="left:575px">${C.izq.n}<span>${C.izq.s}</span></div>
<div class="n" style="right:28px;text-align:right">${C.der.n}<span>${C.der.s}</span></div>
<div class="bar"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="#25b04f"/><path d="M14 52l3.5-11A19 19 0 1 1 25 48z" fill="#fff"/><path d="M24.5 21.5c.7-.7 2-.6 2.6.3l2.2 3.6c.4.7.3 1.6-.3 2.2l-1.4 1.3c1.3 2.8 3.5 5 6.3 6.3l1.3-1.4c.6-.6 1.5-.7 2.2-.3l3.6 2.2c.9.6 1 1.9.3 2.6l-1.6 1.6c-1.4 1.4-3.6 1.8-5.4.9-5.2-2.6-9.4-6.8-12-12-.9-1.8-.5-4 .9-5.4z" fill="#25b04f"/></svg><div class="t">ORIENTACIÓN GRATIS POR WHATSAPP</div><div class="nn">56 5273 6263</div></div>
<script>
const C_PILL=${C.pill?'true':'false'};
function fit(id,max,min,maxW,maxH){const e=document.getElementById(id);let s=max;e.style.fontSize=s+'px';
 while(s>min&&(e.scrollWidth>maxW||(maxH&&e.offsetHeight>maxH))){s-=2;e.style.fontSize=s+'px';}}
fit('l1',84,40,540,260);fit('l2',34,22,540,80);if(C_PILL)fit('pill',56,30,500);
</script></body></html>`;
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1280,height:720}});
 const f=path.join(__dirname,'_colab_tmp.html');fs.writeFileSync(f,html);await p.goto('file://'+f);await p.waitForTimeout(400);
 await p.screenshot({path:path.join(S,`live_${C.num}.png`)});fs.unlinkSync(f);await b.close();})();

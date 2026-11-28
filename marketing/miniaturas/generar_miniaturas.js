// Genera miniaturas de YouTube por tema (1280x720) con Playwright.
// Uso: cd marketing/miniaturas && node generar_miniaturas.js "$(pwd)"
// Para un tema nuevo, agrega un objeto al arreglo V (textos, colores, foto).
const {chromium}=require('playwright');
const S=process.argv[2];
const FOTOS={
  negro:{src:'../fotos/ruben_traje_negro.jpg',w:671,l:-60,t:-215},
  azul:{src:'../fotos/ruben_traje_azul.jpg',w:628,l:-12,t:-199},
};
const V=[
 {id:'aguinaldo',foto:'azul',bg:['#7a1a22','#4a0d14','#24060a'],l1:'¿Y MI AGUINALDO?',l2:'TE LO DEBEN PAGAR',pill:'ANTES DEL 20 DE DIC.',pillBg:'#ffd21f',pillC:'#3a2a00',sub:'Mínimo 15 días de salario',sub2:'Art. 87 de la Ley Federal del Trabajo'},
 {id:'cuanto_me_toca',foto:'negro',bg:['#17704a','#0e4a31','#062417'],l1:'¿CUÁNTO ME TOCA?',l2:'TE CALCULAMOS TU LIQUIDACIÓN',pill:'GRATIS',pillBg:'#ffd21f',pillC:'#3a2a00',sub:'Mándanos tu salario y antigüedad',sub2:'por WhatsApp y te decimos cuánto te toca'},
 {id:'renuncia',foto:'azul',bg:['#5b3391','#3a1f63','#1b0d31'],l1:'¿TE PIDEN FIRMAR TU RENUNCIA?',l2:'ANTES DE FIRMAR',pill:'ASESÓRATE GRATIS',pillBg:'#25b04f',pillC:'#fff',sub:'Conoce tus derechos antes de firmar',sub2:'Asesoría laboral en vivo'},
 {id:'corrieron_de_palabra',foto:'negro',bg:['#b85a12','#7a3a0a','#331804'],l1:'¿TE CORRIERON DE PALABRA?',l2:'EL PATRÓN DEBE DARTE',pill:'AVISO POR ESCRITO',pillBg:'#ffffff',pillC:'#7a3a0a',sub:'Si no te lo dan, se presume injustificado',sub2:'Art. 47 de la Ley Federal del Trabajo'},
];
const html=v=>{const f=FOTOS[v.foto];return `<!doctype html><html><head><meta charset="utf-8"><style>
*{margin:0;padding:0;box-sizing:border-box}
body{width:1280px;height:720px;overflow:hidden;font-family:'Inter Display','Inter',sans-serif;background:radial-gradient(circle at 30% 45%,${v.bg[0]} 0%,${v.bg[1]} 50%,${v.bg[2]} 100%);position:relative;color:#fff}
.foto{position:absolute;right:0;top:0;width:610px;height:604px;overflow:hidden;-webkit-mask-image:linear-gradient(to right,transparent 0%,#000 30%)}
.foto img{position:absolute;width:${f.w}px;left:${f.l}px;top:${f.t}px}
.foto:after{content:"";position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.45),transparent 35%)}
.vivo{position:absolute;top:36px;left:52px;background:#e3192b;padding:9px 20px 9px 16px;border-radius:10px;font-weight:900;font-size:28px;letter-spacing:2px;display:flex;align-items:center;gap:12px;box-shadow:0 6px 18px rgba(0,0,0,.35)}
.vivo i{width:16px;height:16px;border-radius:50%;background:#fff;display:block}
.txt{position:absolute;left:48px;top:104px;width:720px}
.l1{font-weight:900;color:#ffd21f;line-height:.98;letter-spacing:-2px;text-shadow:0 5px 0 rgba(0,0,0,.45),0 10px 30px rgba(0,0,0,.6)}
.l2{font-weight:900;line-height:1;margin-top:18px;letter-spacing:-1px;text-shadow:0 6px 24px rgba(0,0,0,.6);white-space:nowrap}
.pill{display:inline-block;margin-top:22px;background:${v.pillBg};color:${v.pillC};font-weight:900;line-height:1;padding:10px 30px 14px;border-radius:18px;transform:rotate(-3deg);box-shadow:0 10px 30px rgba(0,0,0,.45);white-space:nowrap}
.sub{margin-top:26px;font-weight:800;font-size:30px;color:#f1f1f1;text-shadow:0 3px 12px rgba(0,0,0,.6)}
.sub span{display:block;font-weight:700;font-size:24px;color:rgba(255,255,255,.75);margin-top:4px}
.logo{position:absolute;right:22px;top:18px;width:250px;padding:8px 12px;background:rgba(10,24,36,.78);border-radius:12px}
.nombre{position:absolute;right:30px;top:530px;text-align:right;font-weight:900;font-size:30px;text-shadow:0 3px 12px rgba(0,0,0,.9)}
.nombre span{display:block;font-weight:700;font-size:22px;color:#e6e6e6}
.bar{position:absolute;left:0;right:0;bottom:0;height:116px;background:#0a1824;display:flex;align-items:center;padding:0 52px;gap:22px;border-top:5px solid #25b04f}
.bar svg{width:66px;height:66px;flex:none}.bar .t{font-weight:800;font-size:30px;color:#cfe3ee}.bar .n{font-weight:900;font-size:62px;letter-spacing:1px;margin-left:auto}
</style></head><body>
<div class="foto"><img src="${f.src}"></div><img class="logo" src="logo_despacho.png">
<div class="vivo"><i></i>EN VIVO</div>
<div class="txt"><div class="l1" id="l1">${v.l1}</div><div class="l2" id="l2">${v.l2}</div><div class="pill" id="pill">${v.pill}</div><div class="sub">${v.sub}<span>${v.sub2}</span></div></div>
<div class="nombre">Mtro. Rubén Buerhend<span>Abogado laboralista</span></div>
<div class="bar"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="#25b04f"/><path d="M14 52l3.5-11A19 19 0 1 1 25 48z" fill="#fff"/><path d="M24.5 21.5c.7-.7 2-.6 2.6.3l2.2 3.6c.4.7.3 1.6-.3 2.2l-1.4 1.3c1.3 2.8 3.5 5 6.3 6.3l1.3-1.4c.6-.6 1.5-.7 2.2-.3l3.6 2.2c.9.6 1 1.9.3 2.6l-1.6 1.6c-1.4 1.4-3.6 1.8-5.4.9-5.2-2.6-9.4-6.8-12-12-.9-1.8-.5-4 .9-5.4z" fill="#25b04f"/></svg><div class="t">ORIENTACIÓN GRATIS POR WHATSAPP</div><div class="n">56 5273 6263</div></div>
<script>
function fit(id,max,min,maxW,maxH){const e=document.getElementById(id);let s=max;e.style.fontSize=s+'px';
 while(s>min&&(e.scrollWidth>maxW||(maxH&&e.offsetHeight>maxH))){s-=2;e.style.fontSize=s+'px';}}
document.getElementById('l1').style.width='720px';
fit('l1',96,50,720,200);fit('l2',62,30,720);fit('pill',96,40,660);
</script></body></html>`};
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1280,height:720},deviceScaleFactor:1});
 const fs=require('fs');
 for(const v of V){fs.writeFileSync(`${S}/v_${v.id}.html`,html(v));await p.goto(`file://${S}/v_${v.id}.html`);await p.waitForTimeout(300);
  await p.screenshot({path:`${S}/miniatura_${v.id}.png`});}
 await b.close();})();

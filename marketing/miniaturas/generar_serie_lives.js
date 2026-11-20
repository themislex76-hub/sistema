// Miniaturas de la serie numerada "LIVE #N" (lives de preguntas y respuestas, sin tema único).
// Cada número guarda su combinación en registro.json (y registro.md legible): nunca se repite
// una combinación y dos lives seguidos no comparten color, foto, frase ni juego de preguntas.
// Uso: cd marketing/miniaturas && node generar_serie_lives.js "$(pwd)" DESDE [HASTA]   (ej. 8 o 8 12)
// Un número ya registrado se vuelve a dibujar idéntico. Anotar el video en registro.json -> "video".
const {chromium}=require('playwright');
const S=process.argv[2]||__dirname;
const FOTOS={
  negro:{src:'../fotos/ruben_traje_negro.jpg',w:671,l:-60,t:-215},
  azul:{src:'../fotos/ruben_traje_azul.jpg',w:628,l:-12,t:-199},
  gris:{src:'../fotos/ruben_selfie_traje_gris.jpg',w:744,l:-26,t:-264},
  gris2:{src:'../fotos/ruben_selfie_traje_gris2.jpg',w:744,l:10,t:-250},
  lila:{src:'../fotos/ruben_selfie_corbata_lila.jpg',w:744,l:-20,t:-250},
  selfie:{src:'../fotos/ruben_selfie_cerca_derecha.jpg',w:832,l:-192,t:-264,tapaDerecha:true},
};
const COLORES=[['#1f4e6b','#143650','#0b2233'],['#7a1a22','#4a0d14','#24060a'],['#17704a','#0e4a31','#062417'],['#5b3391','#3a1f63','#1b0d31'],['#b85a12','#7a3a0a','#331804'],['#126b78','#0b4650','#042226']];
const FRASES=[
 {l1:'¿PROBLEMAS EN TU TRABAJO?',l2:'RESPONDO TUS PREGUNTAS',pill:'EN VIVO Y GRATIS'},
 {l1:'TUS DUDAS LABORALES',l2:'RESUELTAS EN VIVO',pill:'ASESORÍA GRATIS'},
 {l1:'PREGÚNTAME LO QUE QUIERAS',l2:'SOBRE TU TRABAJO',pill:'ASESORÍA GRATIS'},
 {l1:'¿TE CORRIERON?',l2:'TE DIGO QUÉ HACER',pill:'ASESORÍA GRATIS'},
 {l1:'CONOCE TUS DERECHOS',l2:'COMO TRABAJADOR',pill:'EN VIVO Y GRATIS'},
 {l1:'¿TU PATRÓN NO TE PAGA?',l2:'AQUÍ TE ORIENTO',pill:'ASESORÍA GRATIS'},
 {l1:'ABOGADO LABORAL',l2:'RESPONDE EN VIVO',pill:'ORIENTACIÓN GRATIS'},
 {l1:'DEFIENDE TU TRABAJO',l2:'RESOLVEMOS TUS DUDAS',pill:'EN VIVO Y GRATIS'},
 {l1:'TRABAJADOR, NO ESTÁS SOLO',l2:'TE ORIENTO EN VIVO',pill:'ASESORÍA GRATIS'},
 {l1:'PREGUNTA SIN PENA',l2:'TE RESPONDO EN VIVO',pill:'ORIENTACIÓN GRATIS'},
 {l1:'ANTES DE FIRMAR',l2:'ESCUCHA ESTO',pill:'ASESORÍA GRATIS'},
 {l1:'NO TE DEJES',l2:'CONOCE LO QUE TE TOCA',pill:'ASESORÍA GRATIS'},
 {l1:'¿DUDAS CON TU TRABAJO?',l2:'RESUÉLVELAS AQUÍ',pill:'ORIENTACIÓN GRATIS'},
];
const PREGUNTAS=[
 ['¿Me pueden correr sin aviso?','¿Cuánto me toca?','¿Y mi aguinaldo?','¿Firmo mi renuncia?'],
 ['¿Me deben pagar vacaciones?','¿Cuánto me toca?','¿Me corrieron embarazada?','¿Qué hago si no me pagan?'],
 ['¿Me pueden bajar el sueldo?','¿Finiquito o liquidación?','¿Y mi aguinaldo?','¿Me pagan horas extra?'],
];
// ---- Registro: cada número de LIVE guarda su combinación para no repetir nunca ----
// registro.json = { "1": {color,frase,preg,foto,generada,video}, ... }
const fs=require('fs'), path=require('path');
const REG=path.join(__dirname,'registro.json');
const reg=fs.existsSync(REG)?JSON.parse(fs.readFileSync(REG,'utf8')):{};
const FOTO_KEYS=['selfie','negro','azul','gris','gris2','lila'];
const DESDE=+(process.argv[3]||1), HASTA=+(process.argv[4]||DESDE);
function usada(c){return Object.values(reg).some(r=>r.color===c.color&&r.frase===c.frase&&r.preg===c.preg&&r.foto===c.foto);}
function siguienteCombo(n){
  // Elige la combinación NO usada con los componentes MENOS usados hasta ahora,
  // y que no comparta color/foto/frase/preguntas con los 2 lives anteriores.
  const usados=Object.entries(reg).filter(([k])=>+k<n).map(([,r])=>r);
  const cuenta=(campo,v)=>usados.filter(r=>r[campo]===v).length;
  const recientes=[reg[n-1],reg[n-2]].filter(Boolean);
  let mejor=null,mejorScore=Infinity;
  for(let color=0;color<COLORES.length;color++)for(const foto of FOTO_KEYS)for(let frase=0;frase<FRASES.length;frase++)for(let preg=0;preg<PREGUNTAS.length;preg++){
    const c={color,frase,preg,foto}; if(usada(c))continue;
    let score=cuenta('color',color)*4+cuenta('foto',foto)*3+cuenta('frase',frase)*2+cuenta('preg',preg)*2;
    recientes.forEach((r,i)=>{const peso=i===0?100:30;
      if(r.color===color)score+=peso; if(r.foto===foto)score+=peso; if(r.frase===frase)score+=peso; if(r.preg===preg)score+=peso;});
    if(score<mejorScore){mejorScore=score;mejor=c;}
  }
  if(!mejor)throw new Error('Ya se usaron todas las combinaciones: agrega frases, preguntas o colores nuevos.');
  return mejor;
}
const hoy=new Date().toISOString().slice(0,10);
const V=[];
for(let n=DESDE;n<=HASTA;n++){
  if(!reg[n]){const c=siguienteCombo(n);reg[n]={...c,generada:hoy,video:''};}
  const r=reg[n];
  V.push({id:'live_'+n,num:n,foto:r.foto,bg:COLORES[r.color],...FRASES[r.frase],
    pillBg:r.color===2?'#ffd21f':'#25b04f',pillC:r.color===2?'#3a2a00':'#fff',
    preg:PREGUNTAS[r.preg],sub:'',sub2:''});
}
fs.writeFileSync(REG,JSON.stringify(reg,null,1));
// Versión legible del registro
const NOMBRES_COLOR=['Azul','Rojo','Verde','Morado','Naranja','Turquesa'];
let md='# Registro de miniaturas "LIVE #N" (YouTube)\n\nCada combinación (color + frase + preguntas + foto) se usa una sola vez.\n\n| # | Color | Frase | Preguntas | Foto | Generada | Video de YouTube |\n|---|---|---|---|---|---|---|\n';
for(const k of Object.keys(reg).map(Number).sort((a,b)=>a-b)){const r=reg[k];
  md+=`| ${k} | ${NOMBRES_COLOR[r.color]} | ${FRASES[r.frase].l1} | Juego ${r.preg+1} | ${r.foto} | ${r.generada||''} | ${r.video||'(pendiente)'} |\n`;}
fs.writeFileSync(path.join(__dirname,'registro.md'),md);
const html=v=>{const f=FOTOS[v.foto];return `<!doctype html><html><head><meta charset="utf-8"><style>
*{margin:0;padding:0;box-sizing:border-box}
body{width:1280px;height:720px;overflow:hidden;font-family:'Inter Display','Inter',sans-serif;background:radial-gradient(circle at 30% 45%,${v.bg[0]} 0%,${v.bg[1]} 50%,${v.bg[2]} 100%);position:relative;color:#fff}
.foto{position:absolute;right:0;top:0;width:610px;height:604px;overflow:hidden;-webkit-mask-image:linear-gradient(to right,transparent 0%,#000 30%)}
.foto img{position:absolute;width:${f.w}px;left:${f.l}px;top:${f.t}px}
.foto:after{content:"";position:absolute;inset:0;background:linear-gradient(to top,rgba(0,0,0,.45),transparent 35%)}
.vivo{position:absolute;top:36px;left:52px;background:#e3192b;padding:9px 20px 9px 16px;border-radius:10px;font-weight:900;font-size:${v.num?40:28}px;letter-spacing:2px;display:flex;align-items:center;gap:12px;box-shadow:0 6px 18px rgba(0,0,0,.35)}
.vivo i{width:16px;height:16px;border-radius:50%;background:#fff;display:block}
.txt{position:absolute;left:48px;top:${v.num?118:104}px;width:720px}
.l1{font-weight:900;color:#ffd21f;line-height:.98;letter-spacing:-2px;text-shadow:0 5px 0 rgba(0,0,0,.45),0 10px 30px rgba(0,0,0,.6)}
.l2{font-weight:900;line-height:1;margin-top:18px;letter-spacing:-1px;text-shadow:0 6px 24px rgba(0,0,0,.6);white-space:nowrap}
.pill{display:inline-block;margin-top:22px;background:${v.pillBg};color:${v.pillC};font-weight:900;line-height:1;padding:10px 30px 14px;border-radius:18px;transform:rotate(-3deg);box-shadow:0 10px 30px rgba(0,0,0,.45);white-space:nowrap}
.chips{margin-top:24px;display:flex;flex-wrap:wrap;gap:10px;width:720px}.chips span{background:rgba(255,255,255,.95);color:#0b2233;font-weight:800;font-size:24px;padding:7px 14px;border-radius:999px;box-shadow:0 4px 12px rgba(0,0,0,.35)}
.sub{margin-top:26px;font-weight:800;font-size:30px;color:#f1f1f1;text-shadow:0 3px 12px rgba(0,0,0,.6)}
.sub span{display:block;font-weight:700;font-size:24px;color:rgba(255,255,255,.75);margin-top:4px}
.logo{position:absolute;right:22px;top:18px;width:250px;padding:8px 12px;background:rgba(10,24,36,.78);border-radius:12px}
.nombre{position:absolute;right:30px;top:530px;text-align:right;font-weight:900;font-size:30px;text-shadow:0 3px 12px rgba(0,0,0,.9)}
.nombre span{display:block;font-weight:700;font-size:22px;color:#e6e6e6}
.bar{position:absolute;left:0;right:0;bottom:0;height:116px;background:#0a1824;display:flex;align-items:center;padding:0 52px;gap:22px;border-top:5px solid #25b04f}
.bar svg{width:66px;height:66px;flex:none}.bar .t{font-weight:800;font-size:30px;color:#cfe3ee}.bar .n{font-weight:900;font-size:62px;letter-spacing:1px;margin-left:auto}
</style></head><body>
<div class="foto"><img src="${f.src}">${f.tapaDerecha?'<div style="position:absolute;right:0;top:0;bottom:0;width:70px;background:linear-gradient(to right,transparent,#0b1620 70%)"></div>':''}</div><img class="logo" src="logo_despacho.png">
<div class="vivo"><i></i>${v.num?"LIVE #"+v.num:"EN VIVO"}</div>
<div class="txt"><div class="l1" id="l1">${v.l1}</div><div class="l2" id="l2">${v.l2}</div><div class="pill" id="pill">${v.pill}</div>${v.preg?`<div class="chips">${v.preg.map(q=>`<span>${q}</span>`).join("")}</div>`:`<div class="sub">${v.sub}<span>${v.sub2}</span></div>`}</div>
<div class="nombre">Lic. Rubén Buerhend<span>Abogado laboralista</span></div>
<div class="bar"><svg viewBox="0 0 64 64"><circle cx="32" cy="32" r="30" fill="#25b04f"/><path d="M14 52l3.5-11A19 19 0 1 1 25 48z" fill="#fff"/><path d="M24.5 21.5c.7-.7 2-.6 2.6.3l2.2 3.6c.4.7.3 1.6-.3 2.2l-1.4 1.3c1.3 2.8 3.5 5 6.3 6.3l1.3-1.4c.6-.6 1.5-.7 2.2-.3l3.6 2.2c.9.6 1 1.9.3 2.6l-1.6 1.6c-1.4 1.4-3.6 1.8-5.4.9-5.2-2.6-9.4-6.8-12-12-.9-1.8-.5-4 .9-5.4z" fill="#25b04f"/></svg><div class="t">ORIENTACIÓN GRATIS POR WHATSAPP</div><div class="n">56 5273 6263</div></div>
<script>
function fit(id,max,min,maxW,maxH){const e=document.getElementById(id);let s=max;e.style.fontSize=s+'px';
 while(s>min&&(e.scrollWidth>maxW||(maxH&&e.offsetHeight>maxH))){s-=2;e.style.fontSize=s+'px';}}
document.getElementById('l1').style.width='720px';
fit('l1',96,50,720,200);fit('l2',62,30,720);fit('pill',96,40,660);
(function(){const t=document.querySelector('.txt');const el=id=>document.getElementById(id);
 let g=0;while(t.getBoundingClientRect().bottom>590&&g<40){g++;
  for(const id of ['l1','l2','pill']){const e=el(id);const s=parseFloat(e.style.fontSize);e.style.fontSize=(s*0.95)+'px';}}})();
</script></body></html>`};
(async()=>{const b=await chromium.launch();const p=await b.newPage({viewport:{width:1280,height:720},deviceScaleFactor:1});
 for(const v of V){fs.writeFileSync(`${S}/v_${v.id}.html`,html(v));await p.goto(`file://${S}/v_${v.id}.html`);await p.waitForTimeout(300);
  await p.screenshot({path:`${S}/${v.id}.png`});fs.unlinkSync(`${S}/v_${v.id}.html`);}
 await b.close();})();

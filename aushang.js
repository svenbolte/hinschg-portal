(function(){
'use strict';
const d=window.hinschgAushangData, canvas=document.getElementById('hinschg-aushang-canvas');
if(!d||!canvas)return;
const W=1400,H=940,ctx=canvas.getContext('2d'),btn=document.getElementById('hinschg-download'),status=document.getElementById('hinschg-aushang-status');
canvas.width=W;canvas.height=H;
let qr=null;
function color(v){const m=String(v||'').match(/^#([0-9a-f]{6}|[0-9a-f]{3})$/i);if(!m)return '#888888';return '#'+(m[1].length===3?m[1].split('').map(x=>x+x).join(''):m[1]).toLowerCase()}
function mix(a,b,t){return '#'+[1,3,5].map(i=>Math.round(parseInt(a.slice(i,i+2),16)*(1-t)+parseInt(b.slice(i,i+2),16)*t).toString(16).padStart(2,'0')).join('')}
const accent=color(d.accent),dark=mix(accent,'#10272b',.77),tint=mix(accent,'#ffffff',.93),ink='#172d33',muted='#455960';
function rect(x,y,w,h,c){ctx.fillStyle=c;ctx.fillRect(x,y,w,h)}
function txt(s,x,y,size,weight='400',fill=ink){ctx.font=weight+' '+size+'px Arial,Helvetica,sans-serif';ctx.fillStyle=fill;ctx.textBaseline='top';ctx.fillText(String(s||''),x,y)}
function wrap(s,max,size,weight='400'){ctx.font=weight+' '+size+'px Arial,Helvetica,sans-serif';let result=[],line='';for(const word of String(s||'').split(/\s+/)){const n=line?line+' '+word:word;if(ctx.measureText(n).width>max&&line){result.push(line);line=word}else line=n}if(line)result.push(line);return result}
function paragraph(s,x,y,w,size,leading,weight='400',fill=ink,maxLines=3){const lines=wrap(s,w,size,weight).slice(0,maxLines);lines.forEach((line,i)=>txt(line,x,y+i*leading,size,weight,fill));return y+lines.length*leading}
function fit(s,w,start=58,min=30){for(let size=start;size>=min;size-=2){if(wrap(s,w,size,'700').length<=2)return size}return min}
function url(s,x,y,w,size,leading,fill=dark,maxLines=3){ctx.font='700 '+size+'px Arial';const out=[];let line='';for(const c of String(s||'')){if(ctx.measureText(line+c).width>w&&line){out.push(line);line=''}line+=c}if(line)out.push(line);out.slice(0,maxLines).forEach((l,i)=>txt(l,x,y+i*leading,size,'700',fill));return y+Math.min(maxLines,out.length)*leading}
function draw(){
rect(0,0,W,H,'#fff');rect(0,0,W,12,accent);
rect(0,12,W,133,dark);ctx.strokeStyle='#fff';ctx.lineWidth=5;ctx.strokeRect(45,48,63,63);txt('!',64,50,59,'700','#fff');
txt('HINWEISGEBERSCHUTZ',132,41,51,'700','#fff');txt('VERTRAULICHE MELDESTELLE',134,103,24,'700','#fff');
const x=54,content=795;
txt('MELDESTELLE FÜR',x,179,23,'700',muted);
const tenantSize=fit(d.tenant,content,62,35);const tenantEnd=paragraph(d.tenant,x,213,content,tenantSize,tenantSize+7,'700',ink,2);
let y=tenantEnd+25;txt('Hinweise sicher und vertraulich melden',x,y,34,'700');y+=52;
y=paragraph('Melden Sie mögliche Rechtsverstöße oder Missstände über unser geschütztes Hinweisgeberportal.',x,y,content,28,38,'400',muted,3)+25;
rect(x,y,content,94,tint);rect(x,y,7,94,accent);txt('SO GEBEN SIE EINEN HINWEIS AB',x+23,y+15,27,'700',dark);txt('QR-Code scannen oder Portal-Link öffnen.',x+23,y+53,26,'400',ink);
y+=128;txt('PORTAL DIREKT ÖFFNEN',x,y,24,'700',muted);
y=url(d.url,x,y+37,content,30,38,dark,3)+26;
rect(x,y,content,2,mix(accent,'#ffffff',.55));y+=20;
txt('BETRIEBEN VON',x,y,22,'700',muted);y+=32;
y=paragraph(d.blogname,x,y,content,32,39,'700',ink,2)+5;
y=paragraph(d.description,x,y,content,24,31,'400',muted,2)+5;
url(d.blogurl,x,y,content,24,31,muted,2);
// QR-Spalte ist bewusst vollflächig und bündig; kein ungenutzter rechter Rand.
const rx=894,rw=452,ry=178,rh=692;rect(rx,ry,rw,rh,tint);ctx.strokeStyle=mix(accent,'#ffffff',.58);ctx.lineWidth=2;ctx.strokeRect(rx,ry,rw,rh);
txt('JETZT HINWEIS',rx+34,ry+35,37,'700',dark);txt('ABGEBEN',rx+34,ry+78,37,'700',dark);
if(qr){const qsize=390,qx=rx+(rw-qsize)/2,qy=ry+160;rect(qx-5,qy-5,qsize+10,qsize+10,'#fff');ctx.imageSmoothingEnabled=false;ctx.drawImage(qr,qx,qy,qsize,qsize);ctx.imageSmoothingEnabled=true}
txt('MIT DEM SMARTPHONE',rx+35,ry+585,25,'700',ink);txt('SCANNEN',rx+35,ry+620,25,'700',ink);
rect(0,H-12,W,12,accent);
}
btn.addEventListener('click',()=>{if(!qr)return;canvas.toBlob(blob=>{if(!blob){status.textContent='PNG-Export fehlgeschlagen.';return}const a=document.createElement('a'),u=URL.createObjectURL(blob);a.href=u;a.download='HinSchG-Schild-'+String(d.tenant||'Mandant').replace(/[^a-z0-9_-]/gi,'-')+'.png';a.click();setTimeout(()=>URL.revokeObjectURL(u),1500)},'image/png')});
draw();try{if(typeof window.HinschgQRCode!=='function')throw Error('QR-Modul fehlt');const q=new window.HinschgQRCode(0,1);q.addData(d.url);q.make();const n=q.getModuleCount(),quiet=4,cell=8,c=document.createElement('canvas');c.width=c.height=(n+quiet*2)*cell;const g=c.getContext('2d');g.fillStyle='#fff';g.fillRect(0,0,c.width,c.height);g.fillStyle='#000';for(let r=0;r<n;r++)for(let col=0;col<n;col++)if(q.isDark(r,col))g.fillRect((col+quiet)*cell,(r+quiet)*cell,cell,cell);qr=c;draw();btn.disabled=false;status.textContent='Schildvorschau bereit.'}catch(e){status.textContent='QR-Code fehlgeschlagen: '+e.message}
})();

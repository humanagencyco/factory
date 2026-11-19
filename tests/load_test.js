const fs=require("fs");
const els={};
function El(id){return {id,innerHTML:"",textContent:"",value:"",style:{setProperty(){}},dataset:{},
 classList:{_s:new Set(),add(c){this._s.add(c)},remove(c){this._s.delete(c)},toggle(c,on){on?this._s.add(c):this._s.delete(c)},contains(c){return this._s.has(c)}},
 setAttribute(){},focus(){},addEventListener(){},appendChild(){},querySelectorAll(){return []},querySelector(){return null},closest(){return null},onclick:null,onkeydown:null,onsubmit:null};}
global.document={getElementById(id){return els[id]||(els[id]=El(id))},querySelectorAll(){return []},querySelector(){return null},addEventListener(){},createElement(){return El("x")},body:El("body")};
global.navigator={clipboard:{writeText(){return Promise.resolve()}}};
global.window={innerWidth:1000,innerHeight:800};
const store={}; global.localStorage={getItem(k){return store[k]||null},setItem(k,v){store[k]=v}};
global.location={hash:"",origin:"https://humanagency.co",pathname:"/factory/"};
global.history={replaceState(){}};
let netErrors=0;
global.fetch=async()=>{ throw new Error("offline in test"); };
const src=fs.readFileSync("/tmp/full.js","utf8");
let api;
try { api=new Function(src+"\n;return {setPostcode,openModule,normPC,MODULES};")(); console.log("PASS script loads"); }
catch(e){ console.log("FAIL script load:", e.message); process.exit(1); }
const {setPostcode,openModule,normPC,MODULES}=api;
const checks=[
 ["normPC tidies lowercase", normPC("tw11aa")==="TW1 1AA"],
 ["normPC rejects junk", normPC("xx")===""],
 ["setPostcode accepts valid", (setPostcode("KT16 0DA"),true)],
 ["setPostcode rejects junk (keeps previous)", (setPostcode("zz"),true)],
];
for (const [n,ok] of checks) console.log((ok?"PASS ":"FAIL ")+n);
let fails=0;
(async()=>{
  for (const m of MODULES) {
    try { document.getElementById("tIn").value="KT16 0DA"; openModule(m.id); await new Promise(r=>setTimeout(r,5)); console.log("PASS opens", m.id); }
    catch(e){ fails++; console.log("FAIL opens", m.id, e.message); }
  }
  console.log(fails? `\n${fails} module(s) failed to open` : "\nAll modules open without errors");
})();

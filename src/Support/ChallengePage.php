<?php

declare(strict_types=1);

namespace Botect\Support;

use Botect\Contracts\ChallengeRenderer;

final class ChallengePage implements ChallengeRenderer
{
    public function render(array $context): string
    {
        $data = json_encode(array_intersect_key($context, array_flip(['ticket', 'nonce', 'verification_path', 'return_to'])), JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        $nonce = htmlspecialchars($context['csp_nonce'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $script = <<<'JS'
(async()=>{
const c=CONTEXT;
const status=document.getElementById("botect-status");
const retry=document.getElementById("botect-retry");
function fail(message){status.textContent=message+" Check that cookies are enabled, then reload to try again.";retry.hidden=false;}
retry.addEventListener("click",()=>location.reload());
// The proof input is ASCII: a fixed prefix plus a base64url nonce.
function sha256(input){
const k=[0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
const h=[0x6a09e667,0xbb67ae85,0x3c6ef372,0xa54ff53a,0x510e527f,0x9b05688c,0x1f83d9ab,0x5be0cd19];
const bytes=Array.from(input,x=>x.charCodeAt(0));const bits=bytes.length*8;
bytes.push(128);while(bytes.length%64!==56)bytes.push(0);
for(let i=7;i>=0;i--)bytes.push(i>=4?0:(bits>>>(i*8))&255);
const rotr=(x,n)=>(x>>>n)|(x<<(32-n));
for(let offset=0;offset<bytes.length;offset+=64){
const w=[];
for(let i=0;i<16;i++){const j=offset+i*4;w[i]=(bytes[j]<<24)|(bytes[j+1]<<16)|(bytes[j+2]<<8)|bytes[j+3];}
for(let i=16;i<64;i++){const x=w[i-15],y=w[i-2];w[i]=(w[i-16]+(rotr(x,7)^rotr(x,18)^(x>>>3))+w[i-7]+(rotr(y,17)^rotr(y,19)^(y>>>10)))|0;}
let [a,b,d0,d,e,f,g,t]=h;
for(let i=0;i<64;i++){const t1=(t+(rotr(e,6)^rotr(e,11)^rotr(e,25))+((e&f)^(~e&g))+k[i]+w[i])|0;const t2=((rotr(a,2)^rotr(a,13)^rotr(a,22))+((a&b)^(a&d0)^(b&d0)))|0;t=g;g=f;f=e;e=(d+t1)|0;d=d0;d0=b;b=a;a=(t1+t2)|0;}
[a,b,d0,d,e,f,g,t].forEach((x,i)=>h[i]=(h[i]+x)|0);
}
return h.map(x=>(x>>>0).toString(16).padStart(8,"0")).join("");
}
if(typeof fetch!=="function"){fail("This browser cannot send the verification request. Use a current browser.");return;}
try{
const input="botect-js-v1:"+c.nonce;
let answer;
if(globalThis.crypto&&crypto.subtle&&typeof TextEncoder==="function"){
try{const digest=await crypto.subtle.digest("SHA-256",new TextEncoder().encode(input));answer=Array.from(new Uint8Array(digest),x=>x.toString(16).padStart(2,"0")).join("");}catch(e){answer=sha256(input);}
}else{answer=sha256(input);}
const r=await fetch(c.verification_path,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/json"},body:JSON.stringify({ticket:c.ticket,answer})});
if(r.status===204){location.assign(c.return_to);}else{fail("Verification was rejected or expired.");}
}catch(e){fail("Verification could not finish. Check your network connection.");}
})();
JS;
        $script = str_replace('CONTEXT', $data, $script);

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title>Checking your browser</title></head><body><h1>Checking your browser</h1><p id="botect-status" role="status">JavaScript and cookies are required to continue.</p><button id="botect-retry" type="button" hidden>Reload and try again</button><noscript>Please enable JavaScript and reload this page.</noscript><script nonce="'.$nonce.'">'.$script.'</script></body></html>';
    }
}

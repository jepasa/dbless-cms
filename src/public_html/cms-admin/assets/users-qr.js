/** Original bounded QR encoder for local TOTP enrollment: version 10-L, byte mode, mask 0, Reed-Solomon parity and a four-module quiet zone. No network, SVG, secret persistence or third-party runtime. */
/** Initialize the private enrollment renderer and expose pure matrix generation for independent decoder qualification. */
(() => {
  'use strict';
  /** Version 10-L uses 274 data codewords in four RS blocks and 18 parity codewords per block. */
  const BLOCKS=[68,68,69,69], PARITY=18, SIZE=57;
  /** GF(256) exponent/log tables with primitive polynomial x^8+x^4+x^3+x^2+1. */
  const exponent=new Uint8Array(512),logarithm=new Uint8Array(256);
  let field=1;
  for(let i=0;i<255;i++){exponent[i]=field;logarithm[field]=i;field<<=1;if(field&256)field^=0x11d;}
  for(let i=255;i<512;i++)exponent[i]=exponent[i-255];

  /** Multiply two GF(256) elements; zero stays zero, with no state changes. */
  function multiply(a,b){return a===0||b===0?0:exponent[logarithm[a]+logarithm[b]];}
  /** Construct the degree-18 Reed-Solomon generator polynomial; returns leading coefficient followed by parity coefficients. */
  function generator(){let result=[1];for(let power=0;power<PARITY;power++){const next=Array(result.length+1).fill(0);for(let i=0;i<result.length;i++){next[i]^=result[i];next[i+1]^=multiply(result[i],exponent[power]);}result=next;}return result;}
  /** RS generator is constant for the selected QR error correction profile. */
  const polynomial=generator();
  /** Compute parity for one data block, returning 18 bytes without mutating the input. */
  function parity(bytes){const result=Array(PARITY).fill(0);for(const byte of bytes){const factor=byte^result.shift();result.push(0);for(let i=0;i<PARITY;i++)result[i]^=multiply(polynomial[i+1],factor);}return result;}
  /** Encode bounded ASCII URI bytes, terminator and standard pads, then interleave independent RS blocks. */
  function codewords(value){
    if(typeof value!=='string'||value.length>271||/[^\x20-\x7e]/.test(value))throw new Error('QR payload exceeds the closed enrollment profile.');
    const bits=[];
    /** Append an unsigned value using an explicit fixed bit width. */
    function append(number,width){for(let shift=width-1;shift>=0;shift--)bits.push((number>>>shift)&1);}
    append(4,4);append(value.length,16);for(const character of value)append(character.charCodeAt(0),8);
    append(0,Math.min(4,274*8-bits.length));while(bits.length%8)bits.push(0);
    const data=[];for(let i=0;i<bits.length;i+=8)data.push(bits.slice(i,i+8).reduce((byte,bit)=>(byte<<1)|bit,0));
    let pad=0;while(data.length<274)data.push(pad++%2?0x11:0xec);
    let cursor=0;const blocks=BLOCKS.map(length=>{const block=data.slice(cursor,cursor+length);cursor+=length;return block;}),checks=blocks.map(parity),result=[];
    for(let i=0;i<69;i++)for(const block of blocks)if(i<block.length)result.push(block[i]);
    for(let i=0;i<PARITY;i++)for(const block of checks)result.push(block[i]);return result;
  }
  /** Generate BCH metadata bits for the fixed format/version payload and polynomial. */
  function bch(value,degree,divisor){let remainder=value<<degree;for(let bit=31-Math.clz32(remainder);bit>=degree;bit--)if(remainder&(1<<bit))remainder^=divisor<<(bit-degree);return (value<<degree)|remainder;}
  /** Build the complete QR matrix; rejects oversized/non-ASCII values and returns independent boolean rows. */
  function matrix(value){
    const bytes=codewords(value),cells=Array.from({length:SIZE},()=>Array(SIZE).fill(null));
    /** Write a reserved function module when inside the matrix. */
    function put(x,y,dark){if(x>=0&&y>=0&&x<SIZE&&y<SIZE)cells[y][x]=Boolean(dark);}
    for(const [left,top] of [[0,0],[SIZE-7,0],[0,SIZE-7]])for(let y=-1;y<=7;y++)for(let x=-1;x<=7;x++)put(left+x,top+y,x>=0&&x<=6&&y>=0&&y<=6&&(x===0||x===6||y===0||y===6||(x>=2&&x<=4&&y>=2&&y<=4)));
    for(const cy of [6,28,50])for(const cx of [6,28,50]){if(cells[cy][cx]!==null)continue;for(let y=-2;y<=2;y++)for(let x=-2;x<=2;x++)put(cx+x,cy+y,Math.max(Math.abs(x),Math.abs(y))===2||(x===0&&y===0));}
    for(let i=8;i<SIZE-8;i++){if(cells[6][i]===null)put(i,6,i%2===0);if(cells[i][6]===null)put(6,i,i%2===0);}
    const format=bch(8,10,0x537)^0x5412,version=bch(10,12,0x1f25);
    for(let i=0;i<15;i++){const dark=(format>>>i)&1;put(8,i<6?i:i<8?i+1:SIZE-15+i,dark);put(i<8?SIZE-i-1:i===8?7:14-i,8,dark);}put(8,SIZE-8,true);
    for(let i=0;i<18;i++){const dark=(version>>>i)&1;put(SIZE-11+i%3,Math.floor(i/3),dark);put(Math.floor(i/3),SIZE-11+i%3,dark);}
    let row=SIZE-1,direction=-1,bit=0;
    for(let column=SIZE-1;column>0;column-=2){if(column===6)column--;for(;;){for(let offset=0;offset<2;offset++){const x=column-offset;if(cells[row][x]!==null)continue;const dark=bit<bytes.length*8?((bytes[bit>>>3]>>>(7-bit%8))&1)!==0:false;put(x,row,dark!==((row+x)%2===0));bit++;}row+=direction;if(row<0||row>=SIZE){row-=direction;direction=-direction;break;}}}
    if(bit!==bytes.length*8)throw new Error('Unexpected QR function-pattern reservation.');return cells;
  }
  /** Paint a private enrollment URI locally on canvas with integer modules and no data URL/network side effects. */
  function draw(canvas,value){if(!value.startsWith('otpauth://totp/'))throw new Error('Invalid enrollment URI.');const cells=matrix(value),scale=4,quiet=4;canvas.width=canvas.height=(SIZE+quiet*2)*scale;const context=canvas.getContext('2d');context.fillStyle='#ffffff';context.fillRect(0,0,canvas.width,canvas.height);context.fillStyle='#111e32';for(let y=0;y<SIZE;y++)for(let x=0;x<SIZE;x++)if(cells[y][x])context.fillRect((x+quiet)*scale,(y+quiet)*scale,scale,scale);}
  /** Pure API is local to this trusted script; it grants no authentication or access privileges. */
  globalThis.DBLESSUsersQR=Object.freeze({matrix,draw});
  if(typeof document!=='undefined')for(const canvas of document.querySelectorAll('[data-users-qr]')){try{draw(canvas,canvas.dataset.usersQr);}catch{const status=canvas.parentElement.querySelector('[data-qr-status]');if(status)status.textContent=canvas.dataset.qrError;canvas.hidden=true;}}
})();

/* Local QR encoder, byte mode, error correction M, versions 1–20.
 * Pattern/RS logic adapted from python-qrcode (BSD); see THIRD-PARTY-NOTICES.txt.
 * No URLs or order data are sent to another service.
 */
(function () {
    'use strict';
    const TABLE=[[1, 26, 16], [1, 44, 28], [1, 70, 44], [2, 50, 32], [2, 67, 43], [4, 43, 27], [4, 49, 31], [2, 60, 38, 2, 61, 39], [3, 58, 36, 2, 59, 37], [4, 69, 43, 1, 70, 44], [1, 80, 50, 4, 81, 51], [6, 58, 36, 2, 59, 37], [8, 59, 37, 1, 60, 38], [4, 64, 40, 5, 65, 41], [5, 65, 41, 5, 66, 42], [7, 73, 45, 3, 74, 46], [10, 74, 46, 1, 75, 47], [9, 69, 43, 4, 70, 44], [3, 70, 44, 11, 71, 45], [3, 67, 41, 13, 68, 42]];
    const ALIGN=[[], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50], [6, 30, 54], [6, 32, 58], [6, 34, 62], [6, 26, 46, 66], [6, 26, 48, 70], [6, 26, 50, 74], [6, 30, 54, 78], [6, 30, 56, 82], [6, 30, 58, 86], [6, 34, 62, 90]];
    const EXP=new Uint8Array(512), LOG=new Uint8Array(256);
    let x=1;
    for(let i=0;i<255;i++){EXP[i]=x;LOG[x]=i;x<<=1;if(x&256)x^=0x11d;}
    for(let i=255;i<512;i++)EXP[i]=EXP[i-255];
    const mul=(a,b)=>a&&b?EXP[LOG[a]+LOG[b]]:0;
    function blocks(version){
        const spec=TABLE[version-1],out=[];
        for(let i=0;i<spec.length;i+=3)for(let j=0;j<spec[i];j++)out.push([spec[i+1],spec[i+2]]);
        return out;
    }
    function codewords(bytes,version){
        const bs=blocks(version),limit=bs.reduce((sum,b)=>sum+b[1],0)*8,bits=[];
        const put=(n,length)=>{for(let i=length-1;i>=0;i--)bits.push((n>>>i)&1);};
        put(4,4);put(bytes.length,version<10?8:16);bytes.forEach(byte=>put(byte,8));
        for(let i=0,n=Math.min(4,limit-bits.length);i<n;i++)bits.push(0);
        while(bits.length%8)bits.push(0);
        const data=[];
        for(let i=0;i<bits.length;i+=8){let b=0;for(let j=0;j<8;j++)b=(b<<1)|bits[i+j];data.push(b);}
        while(data.length<limit/8)data.push(data.length%2===bits.length/8%2?236:17);
        const dcs=[],ecs=[];let offset=0;
        bs.forEach(([total,count])=>{
            const ec=total-count;let generator=[1];
            for(let i=0;i<ec;i++){
                const next=new Array(generator.length+1).fill(0);
                generator.forEach((c,j)=>{next[j]^=c;next[j+1]^=mul(c,EXP[i]);});generator=next;
            }
            const dc=data.slice(offset,offset+count);offset+=count;
            const work=[...dc,...new Array(ec).fill(0)];
            for(let i=0;i<count;i++){
                const lead=work[i]; if(lead)generator.forEach((c,j)=>work[i+j]^=mul(lead,c));
            }
            dcs.push(dc);ecs.push(work.slice(count));
        });
        const result=[];
        for(const group of [dcs,ecs])for(let i=0;i<Math.max(...group.map(a=>a.length));i++)group.forEach(a=>{if(i<a.length)result.push(a[i]);});
        return result;
    }
    function bch(value,poly,shift){
        let work=value<<shift;
        const degree=n=>31-Math.clz32(n);
        while(degree(work)>=degree(poly))work^=poly<<(degree(work)-degree(poly));
        return (value<<shift)|work;
    }
    const masks=[(r,c)=>(r+c)%2===0,r=>r%2===0,(r,c)=>c%3===0,(r,c)=>(r+c)%3===0,
        (r,c)=>(Math.floor(r/2)+Math.floor(c/3))%2===0,(r,c)=>(r*c)%2+(r*c)%3===0,
        (r,c)=>((r*c)%2+(r*c)%3)%2===0,(r,c)=>((r*c)%3+(r+c)%2)%2===0];
    function build(words,version,mask){
        const n=17+4*version, m=Array.from({length:n},()=>new Array(n).fill(null));
        function finder(row,col){
            for(let r=-1;r<8;r++)for(let c=-1;c<8;c++){
                if(row+r<0||row+r>=n||col+c<0||col+c>=n)continue;
                m[row+r][col+c]=(r>=0&&r<=6&&(c===0||c===6))||(c>=0&&c<=6&&(r===0||r===6))||(r>=2&&r<=4&&c>=2&&c<=4);
            }
        }
        finder(0,0);finder(n-7,0);finder(0,n-7);
        for(const r of ALIGN[version-1])for(const c of ALIGN[version-1]){
            if(m[r][c]!==null)continue;
            for(let dr=-2;dr<=2;dr++)for(let dc=-2;dc<=2;dc++)m[r+dr][c+dc]=Math.abs(dr)===2||Math.abs(dc)===2||(dr===0&&dc===0);
        }
        for(let i=8;i<n-8;i++){
            if(m[i][6]===null)m[i][6]=i%2===0;
            if(m[6][i]===null)m[6][i]=i%2===0;
        }
        // ECC M has format identifier 0, so the high bits of the format input are zero.
        const info=bch(mask,0x537,10)^0x5412;
        for(let i=0;i<15;i++){
            const bit=!!((info>>>i)&1);
            m[i<6?i:i<8?i+1:n-15+i][8]=bit;
            m[8][i<8?n-i-1:i<9?15-i:14-i]=bit;
        }
        m[n-8][8]=true;
        if(version>=7){
            const info=bch(version,0x1f25,12);
            for(let i=0;i<18;i++){
                const bit=!!((info>>>i)&1);
                m[Math.floor(i/3)][i%3+n-11]=bit;m[i%3+n-11][Math.floor(i/3)]=bit;
            }
        }
        let row=n-1,dir=-1,index=0;
        for(let col=n-1;col>0;col-=2){
            if(col===6)col--;
            for(;;){
                for(let c=col;c>=col-1;c--)if(m[row][c]===null){
                    let bit=index<words.length*8?!!((words[Math.floor(index/8)]>>>(7-index%8))&1):false;
                    if(masks[mask](row,c))bit=!bit;m[row][c]=bit;index++;
                }
                row+=dir;
                if(row<0||row>=n){row-=dir;dir=-dir;break;}
            }
        }
        return m;
    }
    function penalty(m){
        const n=m.length;let score=0,dark=0;
        const linePenalty=line=>{
            let s=0,run=1;
            for(let i=1;i<=n;i++){
                if(i<n&&line[i]===line[i-1])run++;else{if(run>=5)s+=run-2;run=1;}
            }
            const str=line.map(v=>v?'1':'0').join('');
            for(let i=0;i<=n-11;i++)if(['10111010000','00001011101'].includes(str.slice(i,i+11)))s+=40;
            return s;
        };
        for(let r=0;r<n;r++){
            score+=linePenalty(m[r])+linePenalty(m.map(row=>row[r]));
            for(let c=0;c<n;c++){
                if(m[r][c])dark++;
                if(r<n-1&&c<n-1&&m[r][c]===m[r+1][c]&&m[r][c]===m[r][c+1]&&m[r][c]===m[r+1][c+1])score+=3;
            }
        }
        return score+Math.floor(Math.abs(dark*100/(n*n)-50)/5)*10;
    }
    function matrix(text, fixedMask){
        const bytes=Array.from(new TextEncoder().encode(String(text)));let version=0;
        for(let v=1;v<=20;v++)if(4+(v<10?8:16)+8*bytes.length<=blocks(v).reduce((s,b)=>s+b[1],0)*8){version=v;break;}
        if(!version)throw new Error('Länken är för lång för QR-koden. Använd en kortare menyadress.');
        const words=codewords(bytes,version);
        if(fixedMask!==undefined){if(!Number.isInteger(fixedMask)||fixedMask<0||fixedMask>7)throw new Error('Invalid QR mask');return build(words,version,fixedMask);}
        let best=null,bestScore=Infinity;
        for(let i=0;i<8;i++){const m=build(words,version,i),s=penalty(m);if(s<bestScore){best=m;bestScore=s;}}
        return best;
    }
    function svg(text){
        const m=matrix(text),n=m.length+8;let path='';
        m.forEach((row,r)=>row.forEach((v,c)=>{if(v)path+=`M${c+4},${r+4}h1v1h-1z`;}));
        return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${n} ${n}" role="img" aria-label="QR-kod till beställning" shape-rendering="crispEdges"><rect width="${n}" height="${n}" fill="white"/><path d="${path}" fill="black"/></svg>`;
    }
    const api={matrix,svg};
    if(typeof module!=='undefined'&&module.exports)module.exports=api;
    if(typeof window!=='undefined')window.YooKDSQR=api;
})();

"""Compare local JS QR modules against Python qrcode with identical byte-mode/mask/ECC.
This is algorithm validation, not a claim of a physical phone-camera scan.
Requires qrcode==8.2 and node. Tests stay outside the installable plugin.
"""
import json, subprocess
from pathlib import Path
import qrcode
from qrcode import base, constants, util
ROOT=Path(__file__).resolve().parents[1]
cases=[]
previous=0
for version in range(1,21):
    capacity=sum(b.data_count for b in base.rs_blocks(version,constants.ERROR_CORRECT_M))*8
    text='a'*(1 if version==1 else previous+1)
    previous=(capacity-4-(8 if version<10 else 16))//8
    for mask in range(8): cases.append({'text':text,'version':version,'mask':mask})
for text in ['https://shop.test/meny/?nx_channel=table&nx_table=18','Åäö • ресторан ☕']:
    qr=qrcode.QRCode(error_correction=constants.ERROR_CORRECT_M,border=0)
    qr.add_data(util.QRData(text,mode=util.MODE_8BIT_BYTE));qr.make(fit=True)
    for mask in range(8): cases.append({'text':text,'version':qr.version,'mask':mask})
js="""const qr=require('./yookds-for-woocommerce/assets/js/qr.js');
const input=JSON.parse(require('fs').readFileSync(0,'utf8'));
process.stdout.write(JSON.stringify(input.map(c=>qr.matrix(c.text,c.mask))));"""
res=subprocess.run(['node','-e',js],input=json.dumps(cases),capture_output=True,text=True,cwd=ROOT,check=True,timeout=20)
actual=json.loads(res.stdout)
for case,result in zip(cases,actual,strict=True):
    qr=qrcode.QRCode(version=case['version'],error_correction=constants.ERROR_CORRECT_M,mask_pattern=case['mask'],border=0)
    qr.add_data(util.QRData(case['text'],mode=util.MODE_8BIT_BYTE));qr.make(fit=False)
    assert result==qr.get_matrix(), f"QR differs: v{case['version']} mask{case['mask']}"
print(f'{len(cases)} exact QR matrix comparisons passed: versions 1–20, masks 0–7, UTF-8 and NX link.')
subprocess.run(['node','-e',"""const q=require('./yookds-for-woocommerce/assets/js/qr.js'); const a=require('node:assert/strict');
a.throws(()=>q.matrix('x'.repeat(10000))); a.throws(()=>q.matrix('x',8));
a.ok(q.svg('<script>alert(1)</script>').startsWith('<svg ')); a.ok(!q.svg('<script>alert(1)</script>').includes('<script>'));
console.log('4 QR boundary/output-safety assertions passed.');"""],cwd=ROOT,check=True,timeout=10)

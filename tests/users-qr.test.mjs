/** QR reference matrices were independently decoded by libzbar, including the 271-byte boundary; runtime stays dependency-free. */
import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import vm from 'node:vm';
import {createHash} from 'node:crypto';
import {applications} from './helpers/extension-media.mjs';
const fixtures=JSON.parse(fs.readFileSync(new URL('./fixtures/users-qr.json',import.meta.url)));
for(const application of applications)test(`${path.basename(application)}: local authenticator QR preserves independently decoded reference matrices and capacity limits`,()=>{
 const suffix=fs.existsSync(path.join(application,'system/config/production.php'))?'.min.js':'.js';const sandbox={};vm.runInNewContext(fs.readFileSync(path.join(application,'public_html/cms-admin/assets/users-qr'+suffix),'utf8'),sandbox);const {matrix}=sandbox.DBLESSUsersQR;
 for(const {payload,sha256} of fixtures){const cells=matrix(payload);assert.equal(cells.length,57);assert.ok(cells.every(row=>row.length===57&&row.every(value=>typeof value==='boolean')));assert.equal(createHash('sha256').update(JSON.stringify(cells)).digest('hex'),sha256);}
 for(const invalid of ['x'.repeat(272),'\n','é',null,{}])assert.throws(()=>matrix(invalid),/closed enrollment profile/);
});

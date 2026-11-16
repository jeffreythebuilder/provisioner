'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..'), admin = '/var/www/html/admin';
const id = 'e'.repeat(32), focused = '000fd3000001', other = '000fd3000002';
const view = execFileSync('php', ['-r', '$brands=["digium","alcatel"]; function show_help(){return "";} include ' + JSON.stringify(root + '/views/main.php') + ';'], {encoding:'utf8'});
const scripts = ['/admin/assets/js/jquery-3.6.0.min.js','/admin/assets/js/bootstrap-4.6.1.bundle.min.js','/admin/assets/js/bootstrap-table-dev.min.js'];
const html = '<html><head><meta charset="utf-8"><link rel="stylesheet" href="/admin/assets/css/bootstrap-4.6.1.min.css"></head><body>' + view
  + scripts.map(s=>'<script src="'+s+'"></script>').join('')
  + '<script>localStorage.setItem("provisioner-live-updates","off");window.ajaxErrors=[];window._=s=>s;window.fpbxToast=()=>{};$(document).ajaxError((e,x,s,t)=>window.ajaxErrors.push({url:s.url,status:x.status,error:String(t)}));</script>'
  + ['live-status','discovery','provisioner'].map(s=>'<script src="/admin/modules/provisioner/assets/js/'+s+'.js"></script>').join('')+'</body></html>';
(async()=>{
  const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const requests=[], pageErrors=[];
    let delayMapping = false, reportActive = false, failNextReport = false;
    page.on('pageerror',e=>pageErrors.push(e.stack));
    await page.route('**/*',async route=>{
      const url = new URL(route.request().url());
      if(url.pathname==='/admin/test-mapping')return route.fulfill({contentType:'text/html',body:html});
      if(url.pathname==='/admin/ajax.php') {
        const data = new URLSearchParams(route.request().postData()||'');
        const cmd = url.searchParams.get('command') || data.get('command');requests.push(cmd);
        let response=[];
        if(cmd==='getPendingCount')response={count:0,deploy_mode:'sip_notify'};
        if(cmd==='getDeviceGrid')response=[{id:1,mac_address:focused,extension_display:'101',brand:'digium'},{id:2,mac_address:other,extension_display:'102',brand:'digium'}];
        if(cmd==='getPhoneStatusGrid')response=[{mac:other,extension:'102',brand:'digium'}];
        if(cmd==='getBatchHistory')response={success:true,total:1,total_all:1,batches:[{id,kind:'push',status:'incomplete',device_count:1,created_at:'2026-10-28 12:00:00',counts:{blocked:1}}]};
        if(cmd==='getBatchReport')response={success:true,batch:{id,kind:'push',status:reportActive?'running':'incomplete',created_at:'2026-10-28 12:00:00'},active:reportActive,retry_macs:[],items:[{mac:focused,extension:'101',outcome:reportActive?'running':'blocked',identity_phase:reportActive?'finding':'identity_blocked',next_action:'mapping',generation:'not_started',trigger:'not_started',delivery:'not_started',verification:'not_started',error:'Review mapping.'}]};
        if(cmd==='getBatchReport' && failNextReport) {
          failNextReport=false;
          return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({error:'Fixture report offline'})});
        }
        if(cmd==='getDeviceGrid' && delayMapping)await new Promise(r=>setTimeout(r,500));
        return route.fulfill({contentType:'application/json',body:JSON.stringify(response)}).catch(()=>{});
      }
      const local=url.pathname.startsWith('/admin/modules/provisioner/')?path.join(root,url.pathname.slice('/admin/modules/provisioner/'.length)):path.join(admin,url.pathname.replace(/^\/admin\//,''));
      if(!fs.existsSync(local)||!fs.statSync(local).isFile())return route.fulfill({status:404,body:''});
      return route.fulfill({contentType:local.endsWith('.js')?'application/javascript':local.endsWith('.css')?'text/css':'application/octet-stream',body:fs.readFileSync(local)});
    });
    await page.goto('http://provisioner-ui.test/admin/test-mapping');
    await page.waitForFunction(()=>$('#devicesTable').bootstrapTable('getData').length===2);
    await page.evaluate(()=>{
      $('#devicesTable').bootstrapTable('checkBy',{field:'id',values:[2]});
      $('#phoneStatusTable').bootstrapTable('checkBy',{field:'extension',values:['102']});
      $('#devicesTable').bootstrapTable('resetSearch','102');
    });
    await page.locator('#logs-tab').click();await page.locator('#batchHistoryTab').click();
    await page.locator('#batchHistoryTable .batch-report-link').click();
    await page.locator('#batchReportPhonesTab').click();
    delayMapping=true;
    await page.evaluate(()=>$('#devicesTable').bootstrapTable('refresh'));
    await page.locator('.batch-phone-mapping').click();
    await page.waitForTimeout(650);
    assert.equal(await page.locator('#mappingReportReturn').isVisible(),true);
    assert.equal(await page.locator('#devices .fixed-table-toolbar .search input').inputValue(),focused);
    assert.equal(await page.evaluate(()=>$('#devicesTable').bootstrapTable('getData').length),1);
    await page.locator('#returnToMappingReportBtn').click();
    assert.equal(await page.locator('#devices .fixed-table-toolbar .search input').inputValue(),'102');
    assert.equal(await page.evaluate(()=>$('#devicesTable').bootstrapTable('getSelections').some(r=>r.id===2)),true);
    assert.equal(await page.evaluate(()=>$('#phoneStatusTable').bootstrapTable('getSelections')[0].extension),'102');
    await page.locator('#batchReportPhonesTab').click();await page.locator('.batch-phone-mapping').click();
    await page.locator('#logs-tab').click();await page.locator('#devices-tab').click();
    await page.waitForTimeout(650);
    assert.equal(await page.locator('#mappingReportReturn').isVisible(),false);
    assert.equal(await page.locator('#devices .fixed-table-toolbar .search input').inputValue(),'102');
    const errors=await page.evaluate(()=>window.ajaxErrors);
    console.log(JSON.stringify({errors,pageErrors,requests},null,2));
    assert.deepEqual(errors,[],'Mapping context navigation must not abort an in-flight table request');
    reportActive=true;
    await page.locator('#logs-tab').click();
    await page.locator('#refreshBatchReportBtn').click();
    await page.waitForFunction(()=>$('#batchReportLiveToggleBtn').text().includes('Live Updates · Live'));
    const lastSuccess=await page.locator('#batchReportPollStatus').innerText();
    await page.locator('#batchReportLiveToggleBtn').click();
    const readsPaused=requests.filter(c=>c==='getBatchReport').length;
    await page.waitForTimeout(2200);
    assert.equal(requests.filter(c=>c==='getBatchReport').length,readsPaused);
    assert.match(await page.locator('#batchReportLiveToggleBtn').innerText(),/Paused/);
    assert.equal(await page.locator('#batchReportPollStatus').innerText(),lastSuccess);
    await page.locator('#batchReportLiveToggleBtn').click();
    await page.waitForFunction(()=>$('#batchReportLiveToggleBtn').text().includes('Live Updates · Live'));
    failNextReport=true;
    await page.waitForFunction(()=>$('#batchReportLiveToggleBtn').text().includes('Reconnecting'));
    assert.match(await page.locator('#batchReportPollStatus').innerText(),/Refresh failed/);
    await page.waitForFunction(()=>$('#batchReportLiveToggleBtn').text().includes('Live Updates · Live'));
    await page.locator('#batchReportLiveToggleBtn').click();
    const finalErrors=await page.evaluate(()=>window.ajaxErrors);
    assert.equal(finalErrors.length,1,'Only the intentional HTTP 500 should reach the global AJAX handler');
    assert.equal(finalErrors[0].status,500);
    assert.deepEqual(pageErrors,[]);
    assert.ok(requests.every(c=>['getPhoneStatusGrid','getDeviceGrid','getConfigFileGrid','getPendingCount','getLogGrid','getBatchHistory','getBatchReport'].includes(c)),'No scan, push or save action is permitted');
    console.log('PASS slow mapping navigation, focused/restored search, selections and Live Updates pause/reconnect');
  } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});

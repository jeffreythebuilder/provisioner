'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const admin = '/var/www/html/admin';
const view = execFileSync('php', ['-r', '$brands=["digium","alcatel"]; function show_help(){return "";} include ' + JSON.stringify(root + '/views/main.php') + ';'], {encoding:'utf8'});
const html = '<html><head><link rel="stylesheet" href="/admin/assets/css/bootstrap-4.6.1.min.css"></head><body>' + view
  + '<script src="/admin/assets/js/jquery-3.6.0.min.js"></script>'
  + '<script src="/admin/assets/js/bootstrap-4.6.1.bundle.min.js"></script>'
  + '<script src="/admin/assets/js/bootstrap-table-dev.min.js"></script>'
  + '<script>localStorage.setItem("provisioner-live-updates","off");window.ajaxErrors=[];window.fixtureRequests=[];window._=s=>s;window.fpbxToast=()=>{};$(document).ajaxError(function(e,x,s,t){window.ajaxErrors.push({url:s.url,status:x.status,error:String(t)});});</script>'
  + '<script src="/admin/modules/provisioner/assets/js/live-status.js"></script>'
  + '<script src="/admin/modules/provisioner/assets/js/discovery.js"></script>'
  + '<script src="/admin/modules/provisioner/assets/js/provisioner.js"></script></body></html>';
(async()=>{
  const browser = await chromium.launch({headless:true,args:['--no-sandbox']});
  try {
    const page = await browser.newPage();
    const pageErrors = [];
    let failNextLog = false;
    page.on('pageerror',e=>pageErrors.push(e.stack));
    await page.route('**/*',async route=>{
      const url = new URL(route.request().url());
      if(url.pathname==='/admin/test-logs')return route.fulfill({contentType:'text/html',body:html});
      if(url.pathname==='/admin/ajax.php') {
        const data = new URLSearchParams(route.request().postData()||'');
        const cmd = url.searchParams.get('command') || data.get('command');
        await page.evaluate(cmd=>window.fixtureRequests.push(cmd),cmd);
        const response = cmd==='getPendingCount'?{count:0,deploy_mode:'sip_notify'}
          :cmd==='getBatchHistory'?{success:true,total:0,total_all:0,batches:[]}
          :cmd==='getLogGrid'?[{timestamp:'2026-10-28 12:00:00',action:'fixture',details:'Read-only simulated log',status:'info'}]:[];
        const fail = cmd==='getLogGrid' && failNextLog;
        if(fail)failNextLog=false;
        if(cmd==='getLogGrid')await new Promise(resolve=>setTimeout(resolve,300));
        if(fail)return route.fulfill({status:500,contentType:'application/json',body:JSON.stringify({error:'Fixture log request failed'})});
        return route.fulfill({contentType:'application/json',body:JSON.stringify(response)}).catch(()=>{});
      }
      const local = url.pathname.startsWith('/admin/modules/provisioner/')
        ?path.join(root,url.pathname.slice('/admin/modules/provisioner/'.length))
        :path.join(admin,url.pathname.replace(/^\/admin\//,''));
      if(!fs.existsSync(local)||!fs.statSync(local).isFile())return route.fulfill({status:404,body:''});
      return route.fulfill({contentType:local.endsWith('.js')?'application/javascript':local.endsWith('.css')?'text/css':'application/octet-stream',body:fs.readFileSync(local)});
    });
    await page.goto('http://provisioner-ui.test/admin/test-logs');
    await page.waitForTimeout(500);
    assert.equal(await page.evaluate(()=>window.fixtureRequests.filter(c=>c==='getLogGrid').length),0,'Hidden logs must not eagerly request data');
    await page.locator('#logs-tab').click();
    await page.locator('#batchHistoryTab').click();
    await page.evaluate(()=>{
      for(let i=0;i<5;i++)document.querySelector('.logs-pill[href="#activityLogsSection"]').click();
    });
    await page.waitForTimeout(500);
    const result = await page.evaluate(()=>({errors:window.ajaxErrors,requests:window.fixtureRequests,rows:$('#logsTable').bootstrapTable('getData')}));
    console.log(JSON.stringify(result,null,2));
    assert.deepEqual(result.errors,[],'Tab navigation must not abort table requests and trigger the FreePBX global AJAX error');
    assert.equal(result.rows[0].action,'fixture');
    failNextLog=true;
    await page.locator('.logs-pill[href="#activityLogsSection"]').click();
    await page.locator('#activityLogsError').waitFor({state:'visible'});
    const errors = await page.evaluate(()=>window.ajaxErrors);
    assert.equal(errors.length,1,'Genuine request failures must still reach the FreePBX global AJAX handler');
    assert.equal(errors[0].status,500);
    await page.locator('#retryActivityLogsBtn').click();
    await page.locator('#activityLogsError').waitFor({state:'hidden'});
    assert.equal(await page.evaluate(()=>$('#logsTable').bootstrapTable('getData')[0].action),'fixture');
    assert.deepEqual(pageErrors,[]);
    assert.ok(result.requests.every(c=>['getPhoneStatusGrid','getDeviceGrid','getConfigFileGrid','getPendingCount','getLogGrid','getBatchHistory'].includes(c)),'Browser fixture must only perform read-only requests');
    console.log('PASS real-browser overlapping log refresh, genuine failure and explicit retry');
  } finally {await browser.close();}
})().catch(e=>{console.error(e.stack);process.exitCode=1;});

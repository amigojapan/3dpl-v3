// Offline launcher checks: load both files, start, navigate, and handle failures.
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const main=fs.readFileSync('main.js','utf8');
const html=fs.readFileSync('3dplv3.html','utf8');
const start=main.indexOf('// --- Craftmine launcher ---');
const end=main.indexOf('// --- End craftmine launcher ---');
assert.ok(start>=0 && end>start);
assert.match(html, /id="btn-obj-editor"[^>]*>Object Editor<\/button>\s*<button id="btn-craftmine"/);
function setup() {
    const elements={};
    for(const match of html.matchAll(/id="([^"]+)"/g)) elements[match[1]]={style:{},disabled:false};
    const applied=[],calls=[];
    const context=vm.createContext({
        document:{getElementById(id){assert.ok(elements[id],id);return elements[id];}},
        sharedGameId:'',craftmineLaunchToken:0,isExecuting:false,declarationsValid:true,
        idePanel:elements['ide-panel'],
        restoreIdeButton:{onclick(){calls.push('code');elements['ide-panel'].style.display='flex';}},
        renderer:{domElement:{focus(){calls.push('focus');}}},
        clock:{getDelta(){calls.push('clock');}},
        fetch:async (url,options)=>({ok:true,text:async()=>{
            assert.equal(options.cache,'no-store');
            return fs.readFileSync(url,'utf8');
        }}),
        hideAllMenus(){context.craftmineLaunchToken++;context.isExecuting=false;
            for(const element of Object.values(elements)) element.style.display='none';},
        applyProgramCode(...args){applied.push(args);},
        cs(){calls.push('clear');}
    });
    context.window=context;
    vm.runInContext(main.slice(start,end),context);
    return {context,elements,applied,calls};
}
(async()=>{
    const run=setup();
    await run.elements['btn-craftmine'].onclick();
    assert.equal(run.applied.length,1);
    assert.equal(run.applied[0][0],fs.readFileSync('Programs/craftmine.declarations','utf8'));
    assert.equal(run.applied[0][1],fs.readFileSync('Programs/craftmine.update','utf8'));
    assert.equal(run.applied[0][2],'craftmine');
    assert.equal(run.context.isExecuting,true);
    assert.equal(run.elements['ide-panel'].style.display,'none');
    assert.equal(run.elements['craftmine-menu-controls'].style.display,'flex');
    assert.ok(run.calls.includes('focus') && run.calls.includes('clock'));
    run.elements['btn-craftmine-code'].onclick();
    assert.ok(run.calls.includes('code'));
    run.elements['btn-exit-craftmine'].onclick();
    assert.equal(run.context.isExecuting,false);
    assert.equal(run.elements['creative-menu'].style.display,'block');
    assert.ok(run.calls.includes('clear'));

    const missing=setup();
    missing.context.fetch=async()=>({ok:false,status:404});
    await missing.elements['btn-craftmine'].onclick();
    assert.equal(missing.applied.length,0);
    assert.match(missing.elements['craftmine-launch-status'].textContent,/HTTP 404/);
    assert.equal(missing.elements['btn-craftmine'].disabled,false);

    const cancelled=setup();
    let resolve;
    const pending=new Promise(r=>resolve=r);
    cancelled.context.fetch=async()=>{await pending;return {ok:true,text:async()=>''};};
    const loading=cancelled.elements['btn-craftmine'].onclick();
    assert.equal(cancelled.elements['btn-craftmine'].disabled,true);
    cancelled.context.hideAllMenus();
    resolve();await loading;
    assert.equal(cancelled.applied.length,0,'Navigation cancels a pending launch');

    const broken=setup();broken.context.declarationsValid=false;
    await broken.elements['btn-craftmine'].onclick();
    assert.equal(broken.context.isExecuting,false);
    assert.equal(broken.elements['ide-panel'].style.display,'flex','Show declaration errors in editor');
    assert.ok(broken.calls.includes('clear'),'Remove partially initialized game UI');
    const shared=setup();shared.context.sharedGameId='a'.repeat(32);
    await shared.elements['btn-craftmine'].onclick();assert.equal(shared.applied.length,0);
    console.log('PASS: Creative menu placement, exact program files, automatic start, code/exit navigation, load failures, stale-launch cancellation, and shared-player guard.');
})().catch(error=>{console.error(error);process.exitCode=1;});

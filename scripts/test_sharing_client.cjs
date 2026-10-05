// Offline control-flow tests; browser rendering is deliberately not mocked as a claim of visual testing.
// Run with: node scripts/test_sharing_client.cjs
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const main = fs.readFileSync('main.js', 'utf8');
const html = fs.readFileSync('3dplv3.html', 'utf8');
function section(start, end) { return main.slice(main.indexOf(start), main.indexOf(end, main.indexOf(start))); }

function element(id) {
    const classes = new Set();
    return {
        id, value: '', textContent: '', innerHTML: '', style: {}, attributes: {}, listeners: {}, disabled: false,
        classList: { add(c) { classes.add(c); }, remove(c) { classes.delete(c); }, contains(c) { return classes.has(c); },
            toggle(c) { if (classes.has(c)) { classes.delete(c); return false; } classes.add(c); return true; } },
        setAttribute(k,v) { this.attributes[k]=v; },
        children: [], append(...nodes) { this.children.push(...nodes); },
        replaceChildren(...nodes) { this.children = nodes; },
        addEventListener(type,fn) { this.listeners[type]=fn; },
        focus() { this.focused=true; }, select() { this.selected=true; }, blur() {},
        showModal() { this.open=true; },
        close() { this.open=false; this.listeners.close?.(); },
        click() { return this.onclick?.({target:this,currentTarget:this}); }
    };
}
function deferred() { let resolve; const promise = new Promise(r=>resolve=r); return {promise,resolve}; }

function setup(player = false, gameId = 'creator/Game') {
    const elements = Object.fromEntries([...html.matchAll(/id="([^"]+)"/g)].map(m=>[m[1],element(m[1])]));
    const editing = ['ide-panel','main-menu','account-controls','share-dialog','btn-restore-ide'].map(id=>elements[id]);
    const buttons = ['btn-share-program','btn-save-program','btn-load-program','btn-run'].map(id=>elements[id]);
    const calls = [], copies = [];
    const obj = deferred(), texture = deferred();
    let fetchImpl = async () => ({ok:true,json:async()=>({ok:true,id:gameId,name:'Game',declarations:'window.probe={frames:0}; Obj("actor.json","actor",0,0,0);',update:'window.probe.frames++;'})});
    const context = vm.createContext({
        URL, URLSearchParams, performance, Promise, console: {log: console.log, error(...args) {calls.push(['console-error',...args]);}},
        document: {
            baseURI:'https://example.test/3dpl/3dplv3.html', title:'',
            getElementById(id) { assert.ok(elements[id],id); return elements[id]; },
            createElement(tag) { return element(tag); },
            querySelectorAll(selector) { return selector.includes('button') ? buttons : editing; },
            addEventListener(type,fn) { calls.push(['document-event',type,fn]); },
            execCommand(command) { calls.push(['execCommand',command]); return true; }
        },
        navigator: {clipboard:{async writeText(text) {copies.push(text);}}},
        sessionStorage: {getItem() {if(player)throw new Error('Opaque origin storage unavailable');return 'creator';}},
        fetch: async (...args) => {calls.push(['fetch',...args]);return fetchImpl(...args);},
        setDebugError(message) { calls.push(['debug',message]); },
        clearDebug() {}, resetCamera() {}, setSkyboxVisible() {},
        releaseMobileControls() { calls.push(['release-controls']); },
        setCurrentProgramName(name) { calls.push(['name',name]); }, refreshCodeEditors() {},
        requireLoggedInUser() {return true;},
        hideAllMenus() { editing.forEach(el=>{el.style.display='none';}); },
        confirm(message) {calls.push(['confirm',message]);return true;},
        renderer: {domElement:element('canvas')},
        listener: {context:{state:'suspended',async resume(){calls.push(['resume']);this.state='running';}}},
        playQueuedSounds() {calls.push(['play-sounds']);},
        clock: {getDelta(){calls.push(['clock']);return 0.016;}},
        Obj() { context.addPendingObject(); },
        validCloudProgramName(name) {return !!name;}
    });
    context.window = context;
    if(player) context.THREEDPL_SHARED_GAME = gameId;
    function editor() {
        return {text:'',options:{},setOption(k,v){this.options[k]=v;},getValue(){return this.text;},
            setValue(text){this.text=text;if(this===context.editorUpdate)context.refreshCachedUpdateCode();}};
    }
    context.editorDeclarations = editor(); context.editorUpdate = editor();
    vm.runInContext(section('const sharedGameId', '// --- State Variables ---'),context);
    vm.runInContext(`let loggedInNick = sharedGameId ? '' : sessionStorage.getItem('3dplLoggedInNick');
        let isExecuting=false, declarationsValid=true, suppressDeclarationEvaluation=false, legacySkyMode='default', mapEditorMode=false;
        let vars={}; const cubes=[]; const sharedTextureCache=new Map(); const currentProgramName='Game';
        const Input={_keys:{}}; window.Input=Input;
        function cs(){cubes.length=0; vars={};}
        let cachedUpdateCode='';`,context);
    context.addPendingObject = () => {
        context.assetPromise=obj.promise;
        vm.runInContext('cubes.push({traverse(fn){fn({userData:{ready:assetPromise}});}})',context);
    };
    context.texturePromise=texture.promise;
    vm.runInContext('sharedTextureCache.set("texture",{userData:{threeDPLReady:texturePromise}})',context);
    vm.runInContext(section('function PreProcessor(code)', '// The update editor usually'),context);
    vm.runInContext(section('function refreshCachedUpdateCode()', '// --- ORIGINAL TUTORIALS'),context);
    vm.runInContext(section('function applyProgramCode(', 'programFileInput.onchange'),context);
    vm.runInContext(section('async function readApiResponse(', 'function activeAccountChangedError'),context);
    vm.runInContext(section('// --- Browse published games ---', '// --- End published games browser ---'),context);
    vm.runInContext(section('// --- Share a playable snapshot ---', "window.addEventListener('resize'"),context);
    vm.runInContext(section("document.getElementById('mobile-controller-start').addEventListener", 'mobileControlButtons.forEach(button => {\n    const setPressed'),context);
    return {context,elements,calls,copies,obj,texture,run:code=>vm.runInContext(code,context),
        fetch(fn){fetchImpl=fn;}};
}

(async()=>{
    const author=setup();
    author.context.editorDeclarations.text='qb("cube",0,0,0);';
    author.context.editorUpdate.text='rt("cube",0,1,0);';
    author.run('recordProgramAsset("Objects","private-model.json")');
    await author.elements['btn-share-program'].click();
    const request=author.calls.find(c=>c[0]==='fetch');
    assert.equal(request[1],'server_side/share_program.php');
    assert.equal(request[2].credentials,'same-origin');
    assert.equal(request[2].headers['X-3DPL-Share'],'1');
    const body=JSON.parse(request[2].body);
    assert.equal(body.scope,'creator'); assert.equal(body.declarations,'qb("cube",0,0,0);');
    assert.deepEqual(body.assets,[{library:'Objects',reference:'private-model.json'}]);
    assert.equal(author.copies[0],'https://example.test/3dpl/play.php?share=creator%2FGame');
    assert.ok(!author.calls.some(c=>c[0]==='confirm'), 'First publication needs no overwrite prompt');
    assert.ok(author.elements['share-status'].textContent.includes('copied'));
    author.context.navigator.clipboard.writeText=async()=>{throw new Error('Needs user gesture');};
    await author.elements['btn-copy-share'].click();
    assert.ok(author.calls.some(c=>c[0]==='execCommand'&&c[1]==='copy'));
    assert.ok(author.elements['share-link'].selected);
    author.fetch(async()=>({ok:false,json:async()=>({ok:false,message:'Missing asset: Objects/x.json'})}));
    await author.elements['btn-share-program'].click();
    assert.ok(author.elements['share-status'].textContent.includes('Missing asset'));
    assert.equal(author.elements['btn-share-program'].disabled,false);
    assert.equal(author.elements['btn-copy-share'].disabled,true);

    const overwrite = setup();
    overwrite.fetch(async(url, options)=>{
        const confirmed = JSON.parse(options.body).overwrite === true;
        return {ok:confirmed, status:confirmed?201:409,json:async()=>confirmed
            ? {ok:true,id:'creator/Game'} : {ok:false,error:'overwrite_required',message:'Already shared'}};
    });
    overwrite.context.confirm = message => {overwrite.calls.push(['confirm',message]);return false;};
    await overwrite.elements['btn-share-program'].click();
    assert.equal(overwrite.calls.filter(c=>c[0]==='fetch').length,1,'Cancel must not retry or replace');
    assert.match(overwrite.elements['share-status'].textContent,/cancelled/);
    assert.equal(overwrite.elements['btn-share-program'].disabled,false);
    overwrite.context.confirm = message => {overwrite.calls.push(['confirm',message]);return true;};
    for (let attempt=0; attempt<2; attempt++) {
        await overwrite.elements['btn-share-program'].click();
        assert.equal(overwrite.calls.filter(c=>c[0]==='confirm').length,attempt+2,'Warn on every subsequent share');
        assert.equal(JSON.parse(overwrite.calls.filter(c=>c[0]==='fetch').at(-1)[2].body).overwrite,true);
    }
    const browser = setup();
    browser.fetch(async()=>({ok:true,json:async()=>({ok:true,games:[
        {id:'creator/City game',nick:'creator',name:'City game'},
        {id:'other/Game',nick:'other',name:'<b>Game</b>'}
    ]})}));
    await browser.elements['btn-play-games'].click();
    assert.equal(browser.elements['play-games-menu'].style.display,'block');
    assert.equal(browser.elements['play-games-table'].hidden,false);
    const rows=browser.elements['play-games-list'].children;
    assert.equal(rows.length,2);
    assert.equal(rows[0].children[0].textContent,'creator');
    assert.equal(rows[0].children[1].children[0].href,'https://example.test/3dpl/play.php?share=creator%2FCity+game');
    assert.equal(rows[1].children[1].children[0].textContent,'<b>Game</b>','Game names are plain text');
    assert.equal(browser.calls.find(c=>c[0]==='fetch')[2].credentials,'omit');
    browser.fetch(async()=>({ok:true,json:async()=>({ok:true,games:[]})}));
    await browser.elements['btn-refresh-games'].click();
    assert.equal(browser.elements['play-games-list'].children.length,0);
    assert.match(browser.elements['play-games-status'].textContent,/No games/);
    browser.fetch(async()=>{throw new Error('Offline');});
    await browser.elements['btn-refresh-games'].click();
    assert.match(browser.elements['play-games-status'].textContent,/Offline/);
    assert.equal(browser.elements['btn-refresh-games'].disabled,false,'Catalog errors allow retry');
    assert.match(html, /id="btn-share-program"[^>]*aria-label="Share game"><svg/);
    assert.equal(setup(true,'a'.repeat(32)).run('sharedGameId'),'a'.repeat(32),'Legacy player bootstrap still works');

    const player=setup(true);
    const startup=player.context.startSharedGame();
    await new Promise(r=>setImmediate(r));
    assert.equal(player.run('isExecuting'),false,'Wait for object loads');
    player.obj.resolve(); await new Promise(r=>setImmediate(r));
    assert.equal(player.run('isExecuting'),false,'Wait for textures');
    player.texture.resolve(); await startup;
    assert.equal(player.run('isExecuting'),true,'Autostart without button or credentials');
    assert.equal(player.run('sharedPlayerReady'),true);
    const dialog = player.elements['shared-game-alert'];
    const alertMessage = player.elements['shared-game-alert-message'];
    const alertOK = player.elements['shared-game-alert-ok'];
    player.run('Input._keys.KeyW=true; alert("game over")');
    assert.equal(dialog.open,true,'Existing snapshot alert calls open a player dialog');
    assert.equal(alertMessage.textContent,'game over');
    assert.equal(player.run('sharedPlayerAlertOpen'),true);
    assert.equal(player.run('Input._keys.KeyW'),false,'Release held movement when opening');
    assert.ok(alertOK.focused,'Focus OK for keyboard access');
    // Execute the real animation update gate without rendering a browser.
    const frame = section('    if (isExecuting && !sharedPlayerAlertOpen)', '    if (objectEditorMode)');
    player.run(frame);
    assert.equal(player.context.probe.frames,0,'Updates pause while the dialog is open');
    player.run('alert("<b>next</b>")');
    assert.equal(alertMessage.textContent,'game over','Multiple alerts are queued');
    alertOK.listeners.click();
    assert.equal(dialog.open,true);
    assert.equal(alertMessage.textContent,'<b>next</b>','Message is plain text, never HTML');
    assert.equal(alertMessage.innerHTML,'');
    player.run(frame);
    assert.equal(player.context.probe.frames,0);
    dialog.close(); // Escape dismisses a native dialog through the same close handler.
    assert.equal(player.run('sharedPlayerAlertOpen'),false);
    assert.ok(player.context.renderer.domElement.focused);
    player.run(frame);
    assert.equal(player.context.probe.frames,1,'Updates resume after the last message');
    player.context.probe.frames=0;
    player.run('isExecuting=false; alert("paused")');
    alertOK.listeners.click();
    assert.equal(player.run('isExecuting'),false,'Dismissing must not restart a paused game');
    player.run('isExecuting=true');
    assert.equal(author.context.alert,undefined,'Programming environment keeps native alerts');
    assert.equal(player.elements['shared-player-status'].style.display,'none');
    assert.equal(player.context.editorDeclarations.options.readOnly,'nocursor');
    assert.equal(player.context.editorUpdate.options.readOnly,'nocursor');
    for(const id of ['ide-panel','main-menu','account-controls','share-dialog','btn-restore-ide']) assert.equal(player.elements[id].attributes.inert,'');
    assert.equal(player.elements['mobile-controller-start'].disabled,false);
    assert.equal(player.elements['mobile-controller-toggle'].disabled,false);
    const pauseButton = player.elements['mobile-controller-start'];
    pauseButton.listeners.click({preventDefault(){},currentTarget:pauseButton});
    assert.equal(player.run('isExecuting'),false,'Player can pause');
    assert.equal(pauseButton.textContent,'RESUME');
    pauseButton.listeners.click({preventDefault(){},currentTarget:pauseButton});
    assert.equal(player.run('isExecuting'),true,'Player can resume without editor run button');
    assert.equal(pauseButton.textContent,'PAUSE');
    assert.equal(player.calls.find(c=>c[0]==='fetch')[2].credentials,'omit');
    player.run('eval(cachedUpdateCode)'); assert.equal(player.context.probe.frames,1);
    player.context.unlockSharedPlayerAudio(); await new Promise(r=>setImmediate(r));
    assert.ok(player.calls.some(c=>c[0]==='resume'));
    assert.ok(player.calls.some(c=>c[0]==='play-sounds'));
    assert.match(player.run('programAssetUrl("Objects","private-model.json")'), /shared_asset.php\?share=creator%2FGame&library=Objects&name=private-model.json/);
    await player.elements['btn-share-program'].click();
    assert.equal(player.calls.filter(c=>c[0]==='fetch').length,1,'Player cannot create a share through editing UI');

    const missing=setup(true);
    missing.fetch(async()=>({ok:false,json:async()=>({ok:false,message:'Shared game not found.'})}));
    await missing.context.startSharedGame();
    assert.equal(missing.run('isExecuting'),false);
    assert.ok(missing.elements['shared-player-status'].textContent.includes('not found'));
    assert.equal(missing.elements['shared-player-status'].attributes.role,'alert');
    assert.equal(missing.elements['mobile-controller-start'].disabled,true);
    const broken=setup(true);
    broken.fetch(async()=>({ok:true,json:async()=>({ok:true,name:'Broken',declarations:'missingFunction();',update:''})}));
    await broken.context.startSharedGame();
    assert.equal(broken.run('isExecuting'),false,'Declaration failures must not autostart');
    assert.match(html,/\.shared-player #account-controls/);
    assert.match(fs.readFileSync('play.php','utf8'),/sandbox="allow-scripts allow-pointer-lock allow-downloads"/);
    assert.match(fs.readFileSync('server_side/shared_player.php','utf8'),/sandbox allow-scripts allow-pointer-lock allow-downloads/);
    assert.ok(!fs.readFileSync('play.php','utf8').includes('allow-same-origin'));
    console.log('PASS: named share links, overwrite confirmation and cancellation, public catalog and retry states, share icon, legacy links, clipboard, anonymous auto-start, read-only editor, controller, audio gesture, sandbox alerts, pause/resume, and missing/broken games.');
})().catch(error=>{console.error(error);process.exitCode=1;});

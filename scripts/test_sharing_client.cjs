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
        addEventListener(type,fn) { this.listeners[type]=fn; },
        focus() { this.focused=true; }, select() { this.selected=true; }, blur() {},
        click() { return this.onclick?.({target:this,currentTarget:this}); }
    };
}
function deferred() { let resolve; const promise = new Promise(r=>resolve=r); return {promise,resolve}; }

function setup(player = false) {
    const elements = Object.fromEntries([...html.matchAll(/id="([^"]+)"/g)].map(m=>[m[1],element(m[1])]));
    const editing = ['ide-panel','main-menu','account-controls','share-dialog','btn-restore-ide'].map(id=>elements[id]);
    const buttons = ['btn-share-program','btn-save-program','btn-load-program','btn-run'].map(id=>elements[id]);
    const calls = [], copies = [];
    const gameId = 'a'.repeat(32);
    const obj = deferred(), texture = deferred();
    let fetchImpl = async () => ({ok:true,json:async()=>({ok:true,id:gameId,name:'Game',declarations:'window.probe={frames:0}; Obj("actor.json","actor",0,0,0);',update:'window.probe.frames++;'})});
    const context = vm.createContext({
        URL, URLSearchParams, performance, Promise, console: {log: console.log, error(...args) {calls.push(['console-error',...args]);}},
        document: {
            baseURI:'https://example.test/3dpl/3dplv3.html', title:'',
            getElementById(id) { assert.ok(elements[id],id); return elements[id]; },
            querySelectorAll(selector) { return selector.includes('button') ? buttons : editing; },
            addEventListener(type,fn) { calls.push(['document-event',type,fn]); },
            execCommand(command) { calls.push(['execCommand',command]); return true; }
        },
        navigator: {clipboard:{async writeText(text) {copies.push(text);}}},
        sessionStorage: {getItem() {if(player)throw new Error('Opaque origin storage unavailable');return 'creator';}},
        fetch: async (...args) => {calls.push(['fetch',...args]);return fetchImpl(...args);},
        setDebugError(message) { calls.push(['debug',message]); },
        clearDebug() {}, resetCamera() {}, setSkyboxVisible() {},
        setCurrentProgramName(name) { calls.push(['name',name]); }, refreshCodeEditors() {},
        requireLoggedInUser() {return true;},
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
    assert.equal(author.copies[0],'https://example.test/3dpl/play.php?share='+'a'.repeat(32));
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

    const player=setup(true);
    const startup=player.context.startSharedGame();
    await new Promise(r=>setImmediate(r));
    assert.equal(player.run('isExecuting'),false,'Wait for object loads');
    player.obj.resolve(); await new Promise(r=>setImmediate(r));
    assert.equal(player.run('isExecuting'),false,'Wait for textures');
    player.texture.resolve(); await startup;
    assert.equal(player.run('isExecuting'),true,'Autostart without button or credentials');
    assert.equal(player.run('sharedPlayerReady'),true);
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
    assert.match(player.run('programAssetUrl("Objects","private-model.json")'), /shared_asset.php\?share=[a-f0-9]{32}&library=Objects&name=private-model.json/);
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
    assert.match(fs.readFileSync('play.php','utf8'),/sandbox="allow-scripts allow-pointer-lock"/);
    assert.ok(!fs.readFileSync('play.php','utf8').includes('allow-same-origin'));
    console.log('PASS: Share payload and clipboard, copy fallback, share errors, anonymous auto-start after asset readiness, read-only/inert editor, controller availability, audio gesture, isolated frame configuration, and missing/broken games.');
})().catch(error=>{console.error(error);process.exitCode=1;});

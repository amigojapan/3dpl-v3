// Offline physics, editing, texture-picker, and cleanup checks with real Three.js.
// node scripts/test_craftmine.mjs /path/to/three.module.mjs
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const THREE = await import(process.argv[2] ? pathToFileURL(process.argv[2]).href : 'three');
class Element {
    constructor(tag='div') { this.tagName=tag.toUpperCase(); this.children=[]; this.nodes={}; this.style={}; this.listeners={}; this.value=''; this.open=false; }
    set innerHTML(text) { this.html=text; }
    get innerHTML() { return this.html || ''; }
    appendChild(node) { this.children.push(node); return node; }
    replaceChildren() { this.children=[]; }
    querySelector(selector) { return this.nodes[selector] ||= new Element(selector === 'input' ? 'input' : selector.includes('button') || selector.includes('action') ? 'button' : 'div'); }
    setAttribute(key,value) { this[key]=value; }
    addEventListener(type, fn, options={}) { (this.listeners[type] ||= []).push({fn,options}); }
    emit(type,event={}) { for (const {fn,options} of this.listeners[type] || []) if (!options.signal?.aborted) fn(event); }
    remove() { this.removed=true; }
    focus() {}
    showModal() { this.open=true; }
    close() { this.open=false; this.emit('close'); }
    click() { this.clicked=true; this.onclick?.(); }
    setPointerCapture() {}
}
const main=fs.readFileSync('main.js','utf8');
const declarations=fs.readFileSync('Programs/craftmine.declarations','utf8');
const update=fs.readFileSync('Programs/craftmine.update','utf8');
const names=JSON.parse(main.match(/const availableTextureNames = (\[[\s\S]*?\]);/)[1]);
function setup() {
    const scene=new THREE.Scene(), camera=new THREE.PerspectiveCamera(75,16/9,0.1,1000);
    const canvas=new Element('canvas'), body=new Element('body'), document=new Element('document');
    document.body=body; document.activeElement=body;
    document.createElement=tag=>new Element(tag);
    document.createTextNode=text=>({textContent:text});
    document.exitPointerLock=()=>{document.pointerLockElement=null;};
    const assets=[], textures={}, downloads=[], timers=[];
    const context=vm.createContext({THREE,scene,camera,document,console,performance,AbortController,Blob,
        URL:{createObjectURL(blob){downloads.push(blob);return 'blob:test';},revokeObjectURL(){}},
        setTimeout(fn){timers.push(fn);},
        renderer:{domElement:canvas}, vars:{}, isExecuting:true, sharedGameId:'',
        availableTextureNames:names, Input:{_keys:{},GetKey(k){return !!this._keys[k];}}, Time:{deltaTime:1/60},
        qb(name,x,y,z) { const mesh=new THREE.Mesh(new THREE.BoxGeometry(),new THREE.MeshBasicMaterial()); mesh.name=name; mesh.position.set(x,y,z);scene.add(mesh);return mesh; },
        loadSharedTexture(name) {
            assert.ok(fs.existsSync('Textures/'+name),'Existing texture: '+name);
            if (!textures[name]) { const texture=new THREE.Texture({width:1,height:1});texture.userData.threeDPLReady=Promise.resolve();textures[name]=texture; }
            return textures[name];
        },
        recordProgramAsset(library,name) { assets.push([library,name]); },
        personalTextureResourceUrl(){return '';}, staticTextureResourceUrl(name){return 'Textures/'+name;},
        refreshPersonalTextureLibrary(){return Promise.resolve();},
        mergedTextureEntries(){return names.map(name=>({name}));}
    });
    context.window=context; context.addEventListener=()=>{};
    vm.runInContext(main.slice(main.indexOf('function PreProcessor(code)'),main.indexOf('// The update editor usually')),context);
    context._evalStartTime=performance.now();
    vm.runInContext(context.PreProcessor(declarations),context);
    const compiled=new vm.Script(context.PreProcessor(update));
    const step=(dt=1/60)=>{context._evalStartTime=performance.now();context.Time.deltaTime=dt;compiled.runInContext(context);};
    return {context,game:context.vars.craftmine,scene,camera,canvas,body,document,assets,step,downloads,timers};
}
const run=setup(), game=run.game;
assert.ok(game.count>5000 && game.count<game.limit);
assert.ok(game.blocks['-5,2,-3'].texture==='tree_wood.png');
assert.ok(game.blocks['6,0,2'].texture==='water.png');
assert.equal(new Set(run.assets.map(a=>a[1])).size,names.length,'Every built-in texture is registered for sharing');
const picker=run.body.children.find(node=>node.id==='craftmine-picker');
const ui=run.body.children.find(node=>node.id==='craftmine-ui');
ui.querySelector('[data-action="textures"]').onclick();
assert.equal(picker.open,true);
assert.equal(picker.querySelector('#craftmine-grid').children.length,names.length);
picker.querySelector('input').value='wood'; picker.querySelector('input').oninput();
assert.ok(picker.querySelector('#craftmine-grid').children.length>3);
picker.querySelector('#craftmine-grid').children[0].onclick();
assert.equal(picker.open,false); assert.ok(game.selected.includes('wood'));
for (const fps of [30,60,120]) {
    const trial=setup();
    for(let i=0;i<fps;i++) trial.step(1/fps);
    assert.ok(trial.game.grounded,'Grounded at '+fps);
    assert.ok(trial.game.player.y>=0.5 && trial.game.player.y<0.6);
    trial.game.jump();
    let peak=0;
    for(let i=0;i<fps;i++){trial.step(1/fps);peak=Math.max(peak,trial.game.player.y);}
    assert.ok(peak>1.4 && peak<2,'Jump has bounded arc');
    assert.ok(trial.game.grounded,'Lands after jump');
    trial.game.player.set(0,0.51,10); trial.game.velocity=0;
    trial.context.Input._keys.KeyW=true;
    for(let i=0;i<fps;i++)trial.step(1/fps);
    assert.ok(Math.abs(trial.game.player.z-5.5)<1e-7,'Frame-independent walking');
    trial.scene.getObjectByName('craftmine_world').removeFromParent();
}
game.player.set(0,0.51,10); game.pitch=0; game.yaw=0;
game.add(0,2,7,'stone1.png'); game.syncCamera(); game.aim();
assert.ok(game.target);
assert.equal(game.edit(true),true,'Place on aimed face');
assert.equal(game.blocks['0,2,8'].texture,game.selected);
assert.equal(game.edit(false),true,'Break newly placed block');
assert.equal(game.blocks['0,2,8'],undefined);
assert.equal(game.remove('0,-3,10'),false,'Bedrock protects world base');
game.add(0,1,9,'stone1.png');
assert.equal(game.move('z',-1),false,'Walls block walking');
game.player.set(0,1,10);game.pitch=0;game.syncCamera();
game.add(0,3,9,'stone1.png');
game.aim();
assert.equal(game.edit(true),false,'Cannot place inside player');
assert.equal(game.add(0,25,0,'stone1.png'),false,'Build height bounded');
// Deleting a middle instance must keep the remaining edit targets correct.
game.add(20,0,20,'wood1.png');game.add(21,0,20,'wood1.png');
game.remove('20,0,20');
const last=game.blocks['21,0,20'];
assert.equal(game.batches['wood1.png'].keys[last.index],'21,0,20');
// Exports use the engine's real object validator and preserve each texture.
vm.runInContext(main.slice(main.indexOf('function validateObjectEditorData('),
    main.indexOf('function loadObjectEditorData(')),run.context);
const whole=JSON.parse(JSON.stringify(game.exportObject('world',false)));
run.context.validateObjectEditorData(whole);
assert.equal(whole.length,game.count);
assert.equal(new Set(whole.map(block=>block.cubename)).size,whole.length);
for (const block of whole) {
    assert.equal(block.TextureName,game.blocks[[block.x,block.y,block.z].join(',')].texture);
    assert.deepEqual([block.r,block.g,block.b,block.alpha,block.WrapOnSides],[255,255,255,1,6]);
}
game.corners=[new THREE.Vector3(21,0,20),new THREE.Vector3(20,0,20)];
const selection=JSON.parse(JSON.stringify(game.exportObject('selection',true)));
assert.equal(selection.length,1,'Only occupied cells inside both inclusive corners');
assert.deepEqual([selection[0].x,selection[0].y,selection[0].z],[1,0,0],'Recenter from lower corner, even when that cell is empty');
assert.equal(selection[0].TextureName,'wood1.png');
assert.equal(game.exportObject('selection',false)[0].x,21,'World-coordinate export is optional');
game.corners=[new THREE.Vector3(21,0,20),new THREE.Vector3(21,0,20)];
assert.equal(game.exportObject('selection',true).length,1,'Single-block selection');
game.corners=[new THREE.Vector3(22,20,22),new THREE.Vector3(23,21,23)];
assert.throws(()=>game.exportObject('selection',false),/no blocks/);
game.corners=[new THREE.Vector3(0.5,0,0),new THREE.Vector3(1,1,1)];
assert.throws(()=>game.exportObject('selection',false),/whole numbers/);
game.corners=[null,null];
assert.throws(()=>game.exportObject('selection',false),/both corners/);
// Aimed corner marking and the actual save dialog handlers.
game.player.set(0,0.51,10);game.pitch=0;game.yaw=0;game.syncCamera();
game.markCorner(0);game.markCorner(1);
assert.ok(game.corners[0] && game.corners[1]);
const saveActions=ui.querySelector('#craftmine-bottom').children.find(node=>node.id==='craftmine-save-actions');
const saveDialog=run.body.children.find(node=>node.id==='craftmine-export');
saveActions.querySelector('[data-save]').onclick();
assert.equal(saveDialog.open,true);
assert.equal(saveDialog.querySelector('#craftmine-scope').value,'selection');
assert.equal(saveDialog.querySelector('#craftmine-local').checked,true);
const pausedPosition=game.player.clone();
run.context.Input._keys.KeyW=true;run.step();
assert.ok(game.player.equals(pausedPosition),'Saving pauses movement');
assert.equal(game.edit(false),false,'Saving prevents edits');
saveDialog.querySelector('#craftmine-filename').value='my-house.json';
saveDialog.querySelector('#craftmine-download').onclick();
assert.equal(run.downloads.length,1);
const saved=JSON.parse(await run.downloads[0].text());
run.context.validateObjectEditorData(saved);
assert.equal(saved.length,1);
assert.equal(run.body.children.find(node=>node.tagName==='A').download,'my-house.json');
assert.ok(run.body.children.find(node=>node.tagName==='A').clicked);
saveDialog.querySelector('#craftmine-c0x').value='';saveDialog.emit('input');
assert.equal(saveDialog.querySelector('#craftmine-download').disabled,true);
saveDialog.querySelector('#craftmine-scope').value='world';saveDialog.querySelector('#craftmine-scope').onchange();
assert.equal(saveDialog.querySelector('#craftmine-download').disabled,false,'Whole-world export ignores incomplete selection');
saveDialog.querySelector('#craftmine-export-close').onclick();
assert.equal(saveDialog.open,false);assert.equal(run.context.Input._keys.KeyW,false);
run.scene.getObjectByName('craftmine_world').removeFromParent();
assert.equal(game.alive,false); assert.ok(ui.removed && picker.removed && saveDialog.removed);
assert.equal(run.scene.fog,null);assert.equal(run.canvas.style.touchAction,undefined);
console.log('PASS: world and texture picker, movement/jumping at 30/60/120 FPS, block editing and collision, world/selection JSON validated by 3DPL, inclusive/reversed corners, local origins, download payload, save-dialog pause, invalid/empty selections, and cleanup.');

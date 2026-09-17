// Offline gameplay tests with real Three.js geometry and the v3 engine helpers.
// Run: node scripts/test_dungeon5.mjs /path/to/three.module.mjs
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const THREE = await import(process.argv[2] ? pathToFileURL(process.argv[2]).href : 'three');
const main = fs.readFileSync('main.js','utf8');
const decl = fs.readFileSync('Programs/DUNGEON 5.declarations','utf8');
const update = fs.readFileSync('Programs/DUNGEON 5.update','utf8');
const slice = (start,end) => main.slice(main.indexOf(start),main.indexOf(end,main.indexOf(start)));
const engine = slice('function PreProcessor(code)', '// The update editor usually') +
    slice('const collisionObjectNameIndex','function forEachObjectMaterial') +
    slice('window.mv = function','// Deletion');
const dataCache = {};
const geometry = new THREE.BoxGeometry(1,1,1), material = new THREE.MeshBasicMaterial();
function data(file) {
    if (!dataCache[file]) {
        dataCache[file] = JSON.parse(fs.readFileSync('Objects/'+file));
        for (const voxel of dataCache[file]) {
            if (voxel.TextureName) assert.ok(fs.existsSync('Textures/'+voxel.TextureName),'Texture exists: '+voxel.TextureName);
        }
    }
    return dataCache[file];
}
function setup() {
    const scene = new THREE.Scene(), camera = new THREE.PerspectiveCamera();
    const cubes = [], vars = {}, keys = new Set();
    function register(object,name,x,y,z) {
        object.name=name; object.position.set(x,y,z); scene.add(object); cubes.push(object); return object;
    }
    const context = vm.createContext({
        THREE,scene,camera,cubes,vars,performance,
        Time:{deltaTime:1/60}, KeyCode:Object.fromEntries(['W','S','A','D','Space'].map(k=>[k,k])),
        Input:{GetKey:key=>keys.has(key)},
        objectVoxelCollisionData:new WeakMap(), objectCollisionLocalBoxes:new WeakMap(),
        objectCollisionDataCache:new Map(), loadedObjectCache:dataCache,
        objectJSONUrl:file=>file, finiteMapNumber:(n,fallback)=>Number.isFinite(Number(n))?Number(n):fallback,
        Obj(file,name,x,y,z) {
            const object=register(new THREE.Group(),name,x,y,z);
            for (const voxel of data(file)) {
                const voxelName=voxel.cubename||voxel.name||'cube';
                if (voxelName==='AxisPoint') continue;
                const mesh=new THREE.Mesh(geometry,material); mesh.name=voxelName;
                mesh.position.set(voxel.x,voxel.y,voxel.z); object.add(mesh);
            }
            object.userData={loaded:true,sourceFile:file}; return object;
        },
        LoadMap(file,name) {
            assert.equal(file,'Dungeon2.json');
            const map=register(new THREE.Group(),name,0,0,0);
            map.userData={is3DPLMap:true,loaded:true};
            const entries=JSON.parse(fs.readFileSync('Maps/'+file)).objects;
            assert.equal(entries.length,1,'Preserve the single object in the Unity map');
            for (const entry of entries) {
                const part=context.Obj(entry.file,entry.name,entry.x,entry.y,entry.z);
                part.rotation.y=-entry.rotationY*Math.PI/180; part.scale.setScalar(entry.scale);
                part.userData.mapEntry=entry;
                map.add(part); cubes.splice(cubes.indexOf(part),1);
            }
            return map;
        },
        qb:(name,x,y,z)=>register(new THREE.Mesh(geometry,material),name,x,y,z),
        dl(name) {
            for (let i=cubes.length-1;i>=0;i--) {
                if(cubes[i].name===name) {cubes[i].removeFromParent(); cubes.splice(i,1);}
            }
        }
    });
    context.window=context;
    vm.runInContext(engine,context);
    context._evalStartTime=performance.now(); vm.runInContext(context.PreProcessor(decl),context);
    const compiled=new vm.Script(context.PreProcessor(update));
    function step(dt=1/60) {
        context.Time.deltaTime=dt; context._evalStartTime=performance.now();
        compiled.runInContext(context); scene.updateMatrixWorld(true);
    }
    function object(name) {return cubes.find(object=>object.name===name);}
    function center(name) {return new THREE.Box3().setFromObject(object(name)).getCenter(new THREE.Vector3());}
    function warp(point) {vars.Camera_Colliders.position.copy(point); vars.sync_camera();}
    function clearShots() {for (const shot of vars.bullets) context.dl(shot.object.name); vars.bullets.length=0;}
    function shotAt(name) {
        clearShots(); vars.shoot(); const shot=vars.bullets.at(-1);
        shot.object.position.copy(center(name)); shot.direction.set(0,0,0); return shot;
    }
    return {vars,camera,cubes,context,keys,step,object,center,warp,shotAt,clearShots};
}

const run=setup();
assert.equal(run.vars.hardObjectsArray.length,5);
assert.equal(run.vars.dungeon.children.length,6,'Map includes moving doors and pillar');
assert.equal(run.vars.Camera_Colliders.visible,false);
// Check visibility from the corridors rather than merely placing a shot on a lock.
for(const [name,approach] of [
    ['btn0',[0,1,-10]], ['btn1',[1,1,20]], ['btn2',[12,1,17]],
    ['btn3',[29,1,12]], ['btn4',[29,1,22]], ['btn5',[-24,1,12]],
    ['btn6',[-9,1,20]]
]) {
    const lock=run.vars.locks[name];
    assert.ok(lock.material.isMeshBasicMaterial,'Lock remains visible without scene lighting');
    assert.equal(lock.material.color.getHex(),0xffcc00,'Initial yellow target');
    const size=new THREE.Box3().setFromObject(lock).getSize(new THREE.Vector3());
    assert.ok(size.x>=1.15&&size.y>=1.15,'Large shootable target '+name);
    const origin=new THREE.Vector3(...approach), direction=run.center(name).sub(origin);
    const distance=direction.length();
    run.vars.dungeon.updateWorldMatrix(true,true);
    const ray=new THREE.Raycaster(origin,direction.normalize(),0,distance-0.6);
    assert.equal(ray.intersectObjects(run.vars.dungeon.children,true).length,0,name+' is visible from the accessible side of the wall');
}
for (const name of run.vars.spiders) {
    const originalScale = ['spider5','spider6'].includes(name) ? 0.7 : 0.9;
    assert.ok(Math.abs(Math.abs(run.object(name).scale.x)-originalScale/3)<1e-8,name+' is half the previous size');
    assert.ok(run.object(name).scale.toArray().every(axis=>axis>0),name+' is upright and not mirrored');
    assert.ok(Math.abs(new THREE.Box3().setFromObject(run.object(name)).min.y+0.5)<1e-8,name+' stands on the floor');
}
assert.deepEqual(run.camera.position.toArray(),[0,1,-10]);
assert.ok(run.camera.getWorldDirection(new THREE.Vector3()).z>0.99,'Face into the positive-Z dungeon');
run.keys.add('W'); run.vars.dungeon.userData.loaded=false; run.step();
assert.equal(run.camera.position.z,-10,'Wait for map');
run.vars.dungeon.userData.loaded=true; run.vars.door0.userData.loaded=false; run.step();
assert.equal(run.camera.position.z,-10,'Wait for moving obstacle data');
run.vars.door0.userData.loaded=true;
run.step(); run.keys.clear(); assert.ok(run.camera.position.z>-10,'W enters dungeon without sticking to floor');
run.keys.add('S'); run.step(); run.keys.clear();
assert.ok(Math.abs(run.camera.position.z+10)<1e-8,'S retraces movement');
const originalRotation=run.vars.Camera_Colliders.quaternion.clone();
run.keys.add('D'); run.step(); run.keys.clear();
assert.ok(run.camera.getWorldDirection(new THREE.Vector3()).x<0,'D turns right from the starting heading');
const aimScreen=run.vars.aim.position.clone().project(run.camera);
assert.ok(Math.abs(aimScreen.x)<1e-8&&Math.abs(aimScreen.y)<1e-8,'Reticle stays on the aim direction after turning');
assert.ok(aimScreen.z>-1&&aimScreen.z<1,'Reticle is inside camera clipping planes');
run.keys.add('A'); run.step(); run.keys.clear();
assert.ok(run.vars.Camera_Colliders.quaternion.angleTo(originalRotation)<1e-6,'A reverses turn');

// Real door geometry must block movement from both sides. Opening the door frees the passage.
const collision=setup();
collision.vars.dungeon.clear(); collision.vars.dungeon.add(collision.vars.door0);
for (const [z,distance] of [[8,6],[14,-6]]) {
    collision.warp(new THREE.Vector3(0.5,1,z));
    assert.equal(collision.vars.walk(distance),false,'Closed door blocks '+distance);
    const stopped=collision.vars.Camera_Colliders.position.z;
    assert.ok(distance>0 ? stopped<11 : stopped>11,'Stop on approach side');
}
collision.context.mv('door0',0,10,0);
collision.warp(new THREE.Vector3(0.5,1,8));
assert.equal(collision.vars.walk(6),true,'Opened door permits passage');
assert.ok(Math.abs(collision.vars.Camera_Colliders.position.z-14)<1e-8);

// Shoot the spawn-room lock from the actual starting position, then release Space.
// The door must keep opening after the projectile has passed the small lock.
const entrance=setup();
const doorToLock=entrance.center('btn0').sub(entrance.center('door0'));
const cameraRight=new THREE.Vector3(1,0,0).applyQuaternion(entrance.camera.quaternion);
assert.ok(doorToLock.dot(cameraRight)<0,'Lock is left of the door from the spawn room');
const lockDirection=entrance.center('btn0').sub(entrance.camera.position).normalize();
entrance.context.sr('Camera_Coliders0',0,Math.atan2(lockDirection.x,-lockDirection.z)*180/Math.PI,0);
entrance.vars.sync_camera();
const entranceClosedY=entrance.vars.door0.position.y;
entrance.keys.add('Space'); entrance.step(); entrance.keys.clear();
let openingContinuedAfterHit=false;
for(let frame=0;frame<300;frame++) {
    const previousY=entrance.vars.door0.position.y;
    entrance.step();
    const rise=entrance.vars.door0.position.y-previousY;
    assert.ok(rise>=0&&rise<=entrance.vars.motions.door0.speed/60+1e-8,'Door rises gradually');
    if (rise>0 && !entrance.vars.bullets.some(shot=>entrance.context.cd(shot.object.name,'btn0'))) {
        openingContinuedAfterHit=true;
    }
}
assert.ok(entrance.vars.door0.position.y>=entranceClosedY+3-1e-8,'One shot at the lock opens the spawn door fully');
assert.ok(openingContinuedAfterHit,'Opening continues after bullet leaves lock');
entrance.shotAt('btn0'); entrance.step();
assert.equal(entrance.vars.door0.position.y,entrance.vars.motions.door0.end,'Further hits do not raise the opened door indefinitely');
entrance.warp(new THREE.Vector3(0.5,1,9));
assert.equal(entrance.vars.walk(4),true,'Player can pass through the opened door in the real dungeon');

// Each mechanism finishes after a brief hit, clamps at its endpoint, and can
// reverse the pillar without resetting its position or moving forever.
run.warp(new THREE.Vector3(100,100,100));
run.vars.keyCollected=true;
for (const action of run.vars.buttons) {
    const motion=run.vars.motions[action.motion];
    const before=motion.object.position[motion.axis];
    run.shotAt(action.button); run.step(); run.clearShots();
    assert.equal(run.vars.locks[action.button].material.color.getHex(),0x55ff88,'Activated lock turns green');
    const target=action.reverse ? motion.start : motion.end;
    assert.ok(Math.abs(motion.object.position[motion.axis]-before)<=motion.speed/60+1e-8,'First hit starts gradual '+action.motion);
    for(let frame=0;frame<120;frame++) run.step();
    assert.equal(motion.object.position[motion.axis],target,action.button+' completes movement after bullet disappears');
    run.shotAt(action.button); run.step(); run.clearShots();
    for(let frame=0;frame<30;frame++) run.step();
    assert.equal(motion.object.position[motion.axis],target,'Repeated '+action.button+' hits stay at endpoint');
}
const bridgeBounds=new THREE.Box3().setFromObject(run.vars.bridge);
assert.ok(Math.abs(bridgeBounds.max.x+29.5)<1e-8,'Bridge joins dungeon ledge');
assert.ok(bridgeBounds.min.x<new THREE.Box3().setFromObject(run.object('helly')).max.x,'Bridge reaches helicopter');

// The visible exit lock must ignore shots until the player collects the key.
const exitLock=setup();
exitLock.warp(new THREE.Vector3(-9,1,20));
const exitAim=exitLock.center('btn6').sub(exitLock.camera.position).normalize();
exitLock.context.sr('Camera_Coliders0',0,Math.atan2(exitAim.x,-exitAim.z)*180/Math.PI,0);
exitLock.vars.sync_camera();
exitLock.keys.add('Space'); exitLock.step(); exitLock.keys.clear();
for(let frame=0;frame<180;frame++) exitLock.step();
assert.equal(exitLock.vars.door4.position.y,exitLock.vars.motions.door4.start,'Exit lock cannot open without key');
assert.equal(exitLock.vars.locks.btn6.material.color.getHex(),0xffcc00,'Locked exit must not indicate activation');
exitLock.clearShots();
exitLock.warp(exitLock.center('keys')); exitLock.step();
assert.equal(exitLock.vars.keyCollected,true,'Actual pickup grants key');
assert.equal(exitLock.vars.door4.position.y,exitLock.vars.motions.door4.start,'Pickup alone does not open exit door');
exitLock.warp(new THREE.Vector3(-9,1,20));
exitLock.context.sr('Camera_Coliders0',0,Math.atan2(exitAim.x,-exitAim.z)*180/Math.PI,0);
exitLock.vars.sync_camera();
exitLock.keys.add('Space'); exitLock.step(); exitLock.keys.clear();
for(let frame=0;frame<180;frame++) exitLock.step();
assert.equal(exitLock.vars.door4.position.y,exitLock.vars.motions.door4.end,'Hallway shot opens exit after key pickup');
assert.equal(exitLock.vars.locks.btn6.material.color.getHex(),0x55ff88,'Exit lock confirms activation');
exitLock.context.sr('Camera_Coliders0',0,-90,0);
exitLock.warp(new THREE.Vector3(-11,1,16));
assert.equal(exitLock.vars.walk(4),true,'Player can enter bridge room after shooting its lock');

const puzzle=setup();
puzzle.warp(new THREE.Vector3(100,100,100));
const door4Y=puzzle.vars.door4.position.y;
puzzle.shotAt('keys'); puzzle.step(); puzzle.clearShots();
assert.equal(puzzle.vars.door4.position.y,door4Y,'Shooting key is not a substitute for collecting it');
assert.equal(puzzle.vars.keyCollected,false,'Shooting key does not grant possession');
for(let frame=0;frame<120;frame++) puzzle.step();
assert.equal(puzzle.vars.door4.position.y,door4Y,'Exit remains closed after shooting key');
const door2Y=puzzle.vars.door2.position.y;
puzzle.warp(puzzle.center('keys')); puzzle.step();
assert.equal(puzzle.context.ex('keys'),false,'Collect key');
assert.ok(puzzle.vars.door2.position.y>door2Y&&puzzle.vars.door2.position.y<door2Y+1,'Key pickup starts a gradual opening');
for(let frame=0;frame<150;frame++) puzzle.step();
assert.equal(puzzle.vars.door2.position.y,puzzle.vars.motions.door2.end,'Key pickup completes door2 opening');
for (const [name,start,heading,distance] of [
    ['door1',[2,1,19],90,4],
    ['door2',[13,1,17],90,4],
    ['door4',[-11,1,16],-90,4]
]) {
    const traversal=name==='door1' ? run : name==='door4' ? exitLock : puzzle;
    traversal.context.sr('Camera_Coliders0',0,heading,0);
    traversal.warp(new THREE.Vector3(...start));
    assert.equal(traversal.vars.walk(distance),true,'Can traverse opened '+name+' in dungeon');
}

const hazards=setup();
hazards.warp(hazards.center('spider1')); hazards.step();
assert.deepEqual(hazards.camera.position.toArray(),[0,1,-10],'Live spider1 resets player');
hazards.warp(new THREE.Vector3(100,100,100));
for (const name of hazards.vars.spiders) {
    if (!hazards.context.ex(name)) continue; // Overlapping spiders can share a hit.
    hazards.shotAt(name); hazards.step(); assert.equal(hazards.context.ex(name),false,'Shoot '+name);
}
for (const name of hazards.vars.spiders) assert.equal(hazards.context.ex(name),false);
hazards.clearShots(); hazards.warp(new THREE.Vector3(0,0,7)); hazards.step();
assert.equal(hazards.camera.position.z,7,'Deleted spider reference cannot kill the player');
// Test the visible sickle at ordinary standing height in the real corridor,
// not by teleporting the player onto an obsolete collision marker.
const sickle=setup();
sickle.warp(new THREE.Vector3(0.5,1,25));
sickle.vars.trap1.rotation.set(0,0,0);
sickle.step(0);
assert.deepEqual(sickle.camera.position.toArray(),[0,1,-10],'Downward blade hits a standing player');
sickle.warp(new THREE.Vector3(0.5,1,25));
sickle.vars.trap1.rotation.z=Math.PI;
sickle.step(0);
assert.equal(sickle.camera.position.z,25,'Player is safe while blade is overhead');
let sweptHit=false;
for(let frame=0;frame<240;frame++) {
    sickle.step();
    if(sickle.camera.position.z<0) {sweptHit=true;break;}
}
assert.ok(sweptHit,'Spinning from overhead eventually sweeps through the player');
sickle.warp(new THREE.Vector3(0.5,1,23));
sickle.vars.trap1.rotation.set(0,0,0);
sickle.step(0);
assert.equal(sickle.camera.position.z,23,'Nearby player outside blade plane is safe');
assert.equal(sickle.vars.trap1.getObjectByName('collider_trap').visible,false,'Hide displaced legacy marker');
for(const fps of [30,60,120]) {
    sickle.warp(new THREE.Vector3(0.5,1,24.3));
    sickle.vars.trap1.rotation.set(0,0,0);
    sickle.keys.add('W');
    let hit=false;
    for(let frame=0;frame<fps;frame++) {
        sickle.step(1/fps);
        if(sickle.camera.position.z<0) {hit=true; break;}
    }
    sickle.keys.clear();
    assert.ok(hit,'Walking through spinning blade causes respawn at '+fps+' FPS');
}

const firing=setup(); firing.warp(new THREE.Vector3(100,100,100));
firing.keys.add('Space'); firing.step(); firing.keys.clear();
const shot=firing.vars.bullets[0], initial=shot.object.position.clone(), direction=shot.direction.clone();
firing.keys.add('D'); for(let i=0;i<60;i++) firing.step(); firing.keys.clear();
assert.ok(shot.object.position.distanceTo(initial.addScaledVector(direction,10))<1e-8,'Spinning bullets keep launch direction after player turns');
for(let i=0;i<500;i++) firing.step();
assert.equal(firing.vars.bullets.length,0,'Expired projectiles removed');
assert.equal(firing.cubes.some(object=>object.name.startsWith('dungeon_bullet_')),false);
const rates=[];
for(const fps of [30,60,120]) {
    const sample=setup(); sample.warp(new THREE.Vector3(100,100,100));
    sample.vars.keyCollected=true;
    Object.keys(sample.vars.motions).forEach(name=>sample.vars.trigger_motion(name));
    sample.keys.add('W'); sample.keys.add('Space');
    for(let i=0;i<fps;i++) sample.step(1/fps);
    for(const motion of Object.values(sample.vars.motions)) {
        assert.ok(Math.abs(motion.object.position[motion.axis]-(motion.start+motion.end)/2)<1e-8,
            motion.object.name+' is halfway through movement after one second at '+fps+' FPS');
    }
    rates.push([sample.camera.position.z,sample.vars.bulletSerial]);
}
for (const rate of rates.slice(1)) {
    assert.ok(Math.abs(rate[0]-rates[0][0])<1e-8,'Frame-independent walking');
    assert.equal(rate[1],rates[0][1],'Frame-independent shot cadence');
}
console.log('PASS: assets/textures, Unity map placement, initialization/loading, first-person controls, two-way door collisions, latched door/pillar/bridge movements, key shot/pickup, smaller spiders, visible sickle contacts/respawn, projectile direction/cleanup, and 30/60/120 FPS movement/fire cadence.');

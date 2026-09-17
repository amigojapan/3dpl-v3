// Offline tests using the engine's actual transforms, preprocessor, and collisions.
// Usage: node scripts/test_helicopter_simulator4.mjs /path/to/three.module.mjs
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const THREE = await import(process.argv[2] ? pathToFileURL(process.argv[2]).href : 'three');
const main = fs.readFileSync('main.js', 'utf8');
const declarations = fs.readFileSync('Programs/Helicopet Flight Simulator 4.declarations', 'utf8');
const update = fs.readFileSync('Programs/Helicopet Flight Simulator 4.update', 'utf8');
const map = JSON.parse(fs.readFileSync('Maps/THEMAP.json')).objects;
const sourceCache = {};
function data(file) {
    return sourceCache[file] ||= JSON.parse(fs.readFileSync('Objects/' + file));
}
for (const entry of map) data(entry.file);
const geometry = new THREE.BoxGeometry(1, 1, 1);
const material = new THREE.MeshBasicMaterial();
const slice = (start, end) => main.slice(main.indexOf(start), main.indexOf(end, main.indexOf(start)));
const engine = slice('function PreProcessor(code)', '// The update editor usually') +
    slice('const collisionObjectNameIndex', 'function forEachObjectMaterial') +
    slice('window.mv = function', '// Deletion');

function setup() {
    const scene = new THREE.Scene(), camera = new THREE.PerspectiveCamera(75, 16/9, 0.1, 1000);
    const cubes = [], vars = {}, keys = new Set(), audio = [];
    const context = vm.createContext({
        THREE, scene, camera, cubes, vars, performance,
        objectVoxelCollisionData: new WeakMap(), objectCollisionLocalBoxes: new WeakMap(),
        objectCollisionDataCache: new Map(), loadedObjectCache: sourceCache,
        objectJSONUrl: file => file, finiteMapNumber: (n, fallback) => Number.isFinite(Number(n)) ? Number(n) : fallback,
        Time: {deltaTime: 1/60},
        KeyCode: Object.fromEntries(['LeftArrow','RightArrow','UpArrow','DownArrow','Space','W','S','A','D','Z','X'].map(k=>[k,k])),
        Input: {GetKey: key => keys.has(key)},
        Obj(file, name, x, y, z) {
            const object = new THREE.Group();
            object.name = name; object.position.set(x,y,z); object.userData.loaded = true;
            for (const voxel of data(file)) {
                if (voxel.cubename === 'AxisPoint') continue;
                const mesh = new THREE.Mesh(geometry, material);
                mesh.name = voxel.cubename || voxel.name || 'cube';
                mesh.position.set(voxel.x,voxel.y,voxel.z); object.add(mesh);
            }
            scene.add(object); cubes.push(object); return object;
        },
        LoadMap(file, name) {
            assert.equal(file, 'THEMAP.json');
            const group = new THREE.Group(); group.name = name;
            group.userData = {is3DPLMap:true, loaded:true};
            // Actual map voxel data feeds the engine's collision tree, without rendering meshes.
            for (const entry of map) {
                const part = new THREE.Group();
                part.position.set(entry.x,entry.y,entry.z);
                part.rotation.set(0,-entry.rotationY*Math.PI/180,0,'YXZ');
                part.scale.setScalar(entry.scale);
                part.userData = {mapEntry:entry, loaded:true, sourceFile:entry.file};
                group.add(part);
            }
            scene.add(group); cubes.push(group); return group;
        },
        AttachSound(target, file) {assert.ok(fs.existsSync('Audio/'+file)); audio.push(['attach',target,file]);},
        PlaySoundLoop(target) {audio.push(['loop',target]);},
        SetVolume(target, volume) {audio.push(['volume',target,volume]);},
        SetPitch(target, pitch) {audio.push(['pitch',target,pitch]);}
    });
    context.window = context;
    vm.runInContext(engine, context);
    context._evalStartTime = performance.now();
    vm.runInContext(context.PreProcessor(declarations), context);
    const compiled = new vm.Script(context.PreProcessor(update));
    function step(dt=1/60) {
        context.Time.deltaTime=dt; context._evalStartTime=performance.now();
        compiled.runInContext(context); scene.updateMatrixWorld(true); camera.updateMatrixWorld(true);
    }
    return {scene,camera,vars,keys,audio,context,step};
}

const run = setup();
assert.equal(run.vars.flight_map.children.length,105);
assert.deepEqual(run.vars.helicopter.position.toArray(),[-39.62049,45.35633,107.3032]);
assert.equal(run.vars.propeller.parent,run.vars.helicopter);
assert.equal(run.vars.helicopter_colliders.parent,run.vars.helicopter);
assert.equal(run.vars.helicopter.scale.x,0.25);
for (const file of ['heli_no_proeller.json','propeller.json','helicpter_colliders.json']) {
    assert.ok(declarations.includes(file),'Use tutorial 32 asset '+file);
}
assert.ok(!declarations.includes('vars["car"]') && !declarations.includes('tire1'),'No legacy car/tire identifiers');
assert.ok(run.vars.helicopter.scale.y>0,'Converted helicopter must be upright');
const spawn = run.vars.helicopter.position.clone();
run.vars.flight_map.userData.loaded=false;
run.keys.add('UpArrow'); run.keys.add('Space'); run.step();
assert.ok(run.vars.helicopter.position.equals(spawn),'Wait for asynchronous map load');
run.vars.flight_map.userData.loaded=true;
run.vars.helicopter_colliders.userData.loaded=false; run.step();
assert.ok(run.vars.helicopter.position.equals(spawn),'Wait for collider load too');
run.vars.helicopter_colliders.userData.loaded=true; run.keys.clear();
run.step();
assert.equal(run.context.is_object_colliding_with_map(run.vars.flight_map,run.vars.helicopter),false,'Adjusted spawn is clear of THEMAP');
const frustum = new THREE.Frustum().setFromProjectionMatrix(new THREE.Matrix4().multiplyMatrices(run.camera.projectionMatrix,run.camera.matrixWorldInverse));
const bounds = new THREE.Box3().setFromObject(run.vars.helicopter);
for (const x of [bounds.min.x,bounds.max.x]) for (const y of [bounds.min.y,bounds.max.y]) for (const z of [bounds.min.z,bounds.max.z]) {
    assert.ok(frustum.containsPoint(new THREE.Vector3(x,y,z)),'Entire helicopter and rotor fit the follow view');
}
// Keep the real map for the first flight; all six translation controls retrace their paths.
for (const [key, axis, sign, opposite] of [['UpArrow','z',-1,'DownArrow'],['LeftArrow','x',-1,'RightArrow'],['W','y',1,'S']]) {
    const before=run.vars.helicopter.position.clone(); run.keys.add(key); run.step(); run.keys.clear();
    assert.ok((run.vars.helicopter.position[axis]-before[axis])*sign>0,key+' direction');
    run.keys.add(opposite); run.step(); run.keys.clear();
    assert.ok(run.vars.helicopter.position.distanceTo(before)<1e-8,opposite+' retraces movement');
}
const beforeBoost=run.vars.helicopter.position.y;
run.keys.add('Space'); run.step(); run.keys.clear();
assert.ok(Math.abs(run.vars.helicopter.position.y-beforeBoost-100/60)<1e-8,'Space boost');
run.keys.add('D'); run.step(); run.keys.clear();
assert.ok(run.vars.helicopter.rotation.y<0,'D turns clockwise');
run.keys.add('A'); run.step(); run.keys.clear();
assert.ok(Math.abs(run.vars.helicopter.rotation.y)<1e-8,'A reverses turn');
run.keys.add('Z'); run.step(); run.keys.clear(); run.step();
assert.equal(run.vars.cameraRoll,2,'Camera roll persists through follow updates');
run.keys.add('X'); run.step(); run.keys.clear(); assert.equal(run.vars.cameraRoll,0);
run.keys.add('S'); run.keys.add('Z'); run.step(); run.keys.clear();
assert.equal(run.vars.cameraRoll,0,'Onscreen S legacy alias must not roll camera');
for (const [key,pitch] of [['W',1.7],['S',0.5],['UpArrow',1.2],['DownArrow',1.2]]) {
    run.keys.add(key); run.step(); assert.deepEqual(run.audio.at(-1),['pitch','helicopter',pitch]);
    run.keys.clear(); run.step(); assert.deepEqual(run.audio.at(-1),['pitch','helicopter',1]);
}
assert.equal(run.audio.filter(call=>call[0]==='attach').length,2,'Sounds attach once');
assert.deepEqual(run.audio.filter(call=>call[0]==='loop'),[['loop','helicopter'],['loop','camera']]);
assert.ok(run.audio.some(call=>call[0]==='volume'&&call[2]===0.05));

// Put a thin map voxel in the path of each tutorial 32 probe. Exercise
// the actual collision helper at two headings, including a deliberately large move.
for (const yaw of [0,Math.PI/2]) {
    for (const [key,probe,axis,sign] of [['UpArrow','collider_front','z',-1],['DownArrow','collider_back','z',1],['LeftArrow','collider_left','x',-1],['RightArrow','collider_right','x',1],['S','collider_bottom','y',-1],['W','collider_top','y',1],['Space','collider_top','y',1]]) {
        run.vars.flight_map.clear(); run.vars.helicopter.position.set(0,40,0); run.vars.helicopter.rotation.set(0,yaw,0);
        run.scene.updateMatrixWorld(true);
        const direction=new THREE.Vector3(); direction[axis]=sign; direction.applyQuaternion(run.vars.helicopter.quaternion);
        const point=run.vars.helicopter.getObjectByName(probe).getWorldPosition(new THREE.Vector3());
        const wall=new THREE.Mesh(geometry,material); wall.position.copy(point).addScaledVector(direction,1.2);
        run.vars.flight_map.add(wall);
        const initial=run.vars.helicopter.position.clone();
        run.vars.flightSpeed=100; run.keys.add(key); run.step(0.05); run.keys.clear();
        const traveled=run.vars.helicopter.position.clone().sub(initial).dot(direction);
        assert.ok(traveled>=0&&traveled<0.7,key+' stops before thin wall at yaw '+yaw);
        assert.equal(run.context.is_object_colliding_with_map(run.vars.flight_map,run.vars.helicopter,probe),false,'Collision rolls back to clear position');
    }
}

const results=[];
for (const fps of [30,60,120]) {
    const sample=setup(); sample.vars.flight_map.clear(); sample.keys.add('UpArrow');
    for(let frame=0;frame<fps;frame++) sample.step(1/fps);
    results.push({position:sample.vars.helicopter.position.clone(),rotor:sample.vars.propeller.quaternion.clone()});
}
for(const result of results.slice(1)) {
    assert.ok(result.position.distanceTo(results[0].position)<1e-8,'Frame-independent flight');
    assert.ok(result.rotor.angleTo(results[0].rotor)<1e-6,'Frame-independent rotor speed');
}
// Compare normal controls directly with the actual tutorial 32 update at 60 FPS.
const tutorial32 = slice('    32: {', '    33: {');
const tutorialUpdate = tutorial32.match(/upd: `([\s\S]*?)`/)[1];
for (const key of ['W','S','A','D','UpArrow','DownArrow','LeftArrow','RightArrow']) {
    const simulator=setup(), tutorial=setup();
    for (const sample of [simulator,tutorial]) {
        sample.vars.flight_map.clear();
        sample.vars.helicopter.rotation.y=0.4;
        sample.keys.add(key);
    }
    simulator.step();
    tutorial.context._evalStartTime=performance.now();
    vm.runInContext(tutorial.context.PreProcessor(tutorialUpdate),tutorial.context);
    assert.ok(simulator.vars.helicopter.position.distanceTo(tutorial.vars.helicopter.position)<1e-8,key+' matches tutorial 32 movement');
    assert.ok(simulator.vars.helicopter.quaternion.angleTo(tutorial.vars.helicopter.quaternion)<1e-6,key+' matches tutorial 32 steering');
    assert.ok(simulator.vars.propeller.quaternion.angleTo(tutorial.vars.propeller.quaternion)<1e-6,key+' matches tutorial 32 rotor rate');
    assert.ok(simulator.camera.position.distanceTo(tutorial.camera.position)<1e-8,key+' follows heading');
}
console.log('PASS: THEMAP assets/spawn, asynchronous loading guard, complete camera framing, tutorial 32 controls and boost, rotor parenting, camera roll, looped audio and pitch release, six collision directions and boost at two headings, and 30/60/120 FPS consistency.');

// Reuse the dungeon harness with actual engine transforms/collisions and JSON voxels.
// Run: node scripts/test_dungeon6.mjs /path/to/three.module.mjs
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const THREE = await import(process.argv[2] ? pathToFileURL(process.argv[2]).href : 'three');
const harness = fs.readFileSync('scripts/test_dungeon5.mjs','utf8');
const start = harness.indexOf('const main =');
const end = harness.indexOf('const run=setup();');
assert.ok(start>=0&&end>start,'Shared dungeon harness found');
const setup = new Function('fs','vm','assert','THREE',
    harness.slice(start,end).replaceAll('Programs/DUNGEON 5.','Programs/DUNGEON 6.') + '\nreturn setup;'
)(fs,vm,assert,THREE);
function game() {
    const run=setup(); run.alerts=[];
    run.context.alert=message=>run.alerts.push(message);
    return run;
}
function settle(run,frames=120,fps=60) {for(let i=0;i<frames;i++) run.step(1/fps);}

assert.ok(fs.existsSync('Programs/DUNGEON 6.declarations'),'Matching program filenames');
const floor=game();
settle(floor);
assert.ok(Math.abs(floor.camera.position.y-1)<1e-8,'No floor sinking or jitter at spawn');
assert.equal(floor.vars.grounded,true);
floor.warp(new THREE.Vector3(0,4,-10));
floor.vars.dungeon.userData.loaded=false;
settle(floor,10);
assert.equal(floor.camera.position.y,4,'No gravity through an unloaded map');
floor.vars.dungeon.userData.loaded=true;
settle(floor);
assert.ok(floor.camera.position.y>=1-1e-8&&floor.camera.position.y<=1.051,'Fall onto the actual floor');
assert.equal(floor.vars.grounded,true);

for(const fps of [30,60,120]) {
    const air=game(); air.warp(new THREE.Vector3(100,10,100));
    settle(air,fps,fps);
    assert.ok(Math.abs(air.camera.position.y-4)<1e-8,'Tutorial 18 fall rate at '+fps+' FPS');
    assert.equal(air.vars.grounded,false);
    assert.equal(air.alerts.length,0);
}

const gap=game(); gap.warp(new THREE.Vector3(-35,1,14.5));
settle(gap,30);
assert.ok(gap.camera.position.y<-1,'Retracted bridge does not support the gap');
settle(gap,240);
assert.deepEqual(gap.camera.position.toArray(),[0,1,-10],'Falling into the void respawns');
assert.equal(gap.alerts.length,0);

const bridge=game(); bridge.vars.trigger_motion('bridge'); settle(bridge);
bridge.warp(new THREE.Vector3(-36,1,14.5)); settle(bridge);
assert.ok(Math.abs(bridge.camera.position.y-1)<1e-8,'Deployed bridge supports feet');
assert.equal(bridge.alerts.length,0,'Bridge is not the helicopter cabin');
bridge.warp(new THREE.Vector3(-47,-8,14.5)); bridge.step();
assert.equal(bridge.alerts.length,0,'Falling under the helicopter does not win');
bridge.warp(new THREE.Vector3(-47,2,14.5)); bridge.step();
assert.equal(bridge.alerts.length,0,'Must land in the cabin before boarding');
settle(bridge,30);
assert.deepEqual(bridge.alerts,['game over'],'Landing inside the cabin ends the game');
settle(bridge,120);
assert.equal(bridge.alerts.length,1,'Alert is not repeated every frame');
bridge.vars.respawn(); bridge.warp(new THREE.Vector3(-47,1,14.5)); settle(bridge,10);
assert.equal(bridge.alerts.length,1,'Returning to the helicopter does not repeat the alert');

// Walk the full route across the deployed bridge using normal controls.
for(const fps of [30,60,120]) {
    const route=game(); route.vars.trigger_motion('bridge'); settle(route);
    route.warp(new THREE.Vector3(-29,1,14.5));
    route.context.sr('Camera_Coliders0',0,-90,0);
    route.keys.add('W');
    for(let frame=0;frame<fps*2;frame++) {
        route.step(1/fps);
        assert.ok(route.camera.position.y>=0.99,'Continuous support from dungeon across bridge into cabin');
        if(route.camera.position.x>-46+1e-8) assert.equal(route.alerts.length,0,'Rotor bounding box must not trigger victory');
    }
    route.keys.clear();
    assert.ok(route.camera.position.x<=-46,'Normal walking reaches the cabin at '+fps+' FPS');
    assert.deepEqual(route.alerts,['game over'],'Normal boarding alerts once at '+fps+' FPS');
}
console.log('PASS: loading guard, constant gravity at 30/60/120 FPS, floor/bridge/cabin support, gap falling and respawn, real bridge traversal, and one-time boarding alert without rotor/airborne false positives.');

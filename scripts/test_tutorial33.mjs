// Exercise the car tutorial with actual Three.js transforms and map collisions.
// Run: node scripts/test_tutorial33.mjs /path/to/three.module.mjs
import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import {pathToFileURL} from 'node:url';
const THREE = await import(process.argv[2] ? pathToFileURL(process.argv[2]).href : 'three');
const harness = fs.readFileSync('scripts/test_helicopter_simulator4.mjs', 'utf8');
const start = harness.indexOf('const main =');
const end = harness.indexOf('const run = setup();');
assert.ok(start >= 0 && end > start);
const setup = new Function('fs', 'vm', 'assert', 'THREE',
    harness.slice(start, end).replaceAll('Programs/Helicopet Flight Simulator 4.', 'Programs/Tutorial 33 Car Simulator.')
    + '\nreturn setup;')(fs, vm, assert, THREE);
const run = setup();
// The flight harness omits Obj's legacy Euler accessors; the car's tires use them.
const engine = fs.readFileSync('main.js', 'utf8');
vm.runInContext(engine.slice(engine.indexOf('function injectUnityCompatibility('),
    engine.indexOf('// Obj() collider meshes')), run.context);
run.scene.traverse(object => run.context.injectUnityCompatibility(object));
assert.deepEqual(run.vars.car.position.toArray(), [17.38, 2.32, 32.48]);
assert.deepEqual(run.vars.land.position.toArray(), [0, 0, 0]);
assert.equal(run.vars.land.rotation.y, 0);
assert.equal(run.vars.car_colliders.parent, run.vars.car);
assert.ok(run.vars.car_colliders.position.length() < 1e-8, 'Probes stay centered on the relocated car');
assert.deepEqual(run.audio.filter(call => call[0] === 'attach'), [
    ['attach', 'car0', 'car.wav'], ['attach', 'camera', 'NOW7.wav']
]);
assert.deepEqual(run.audio.filter(call => call[0] === 'loop'), [
    ['loop', 'car0'], ['loop', 'camera']
]);
assert.ok(run.audio.some(call => call[0] === 'volume' && call[1] === 'camera' && call[2] === 0.4));
assert.ok(run.audio.some(call => call[0] === 'volume' && call[1] === 'car0' && call[2] === 0.25));
function checkCamera() {
    assert.ok(run.camera.position.clone().sub(run.vars.car.position)
        .distanceTo(new THREE.Vector3(-2, 2, -3)) < 1e-8, 'Original fixed follow offset');
    const radians = Math.PI / 180;
    const expected = new THREE.Vector3(Math.sin(10 * radians) * Math.cos(22 * radians),
        -Math.sin(22 * radians), Math.cos(10 * radians) * Math.cos(22 * radians));
    assert.ok(run.camera.getWorldDirection(new THREE.Vector3()).distanceTo(expected) < 1e-8,
        'Web camera matches original Unity pitch 22 and yaw 10');
    const view = new THREE.Frustum().setFromProjectionMatrix(
        new THREE.Matrix4().multiplyMatrices(run.camera.projectionMatrix, run.camera.matrixWorldInverse));
    assert.ok(view.intersectsBox(new THREE.Box3().setFromObject(run.vars.car)), 'Car is visible');
}
run.step();
checkCamera();
const initial = run.vars.car.position.clone();
run.keys.add('UpArrow');
run.step();
assert.ok(run.vars.car.position.distanceTo(initial) > 0.1, 'Original spawn can drive forward');
assert.ok(run.audio.some(call => call[0] === 'pitch' && call[2] === 1.7), 'Driving raises engine pitch');
checkCamera();
run.keys.clear();
run.step();
assert.deepEqual(run.audio.filter(call => call[0] === 'pitch').at(-1), ['pitch', 'car0', 1]);
run.keys.add('RightArrow');
run.step();
checkCamera();
assert.equal(run.audio.filter(call => call[0] === 'attach').length, 2, 'Audio attaches only once');
console.log('PASS: original spawn, centered probes, forward driving, Unity camera direction and fixed follow offset, visible car, looped engine/music, driving/idle pitch, and no repeated audio attachments.');

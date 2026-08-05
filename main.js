import * as THREE from 'three';

// --- State Variables ---
let cubes = [];
let vars = {};
let isExecuting = false;
let clock = new THREE.Clock();

// Editor States
let objectEditorMode = false;
let mapEditorMode = false;
let editorPointer = null;
let actionCooldown = 0;

// Mouse Look States
let mouseLookEnabled = false;
let camPitch = 0;
let camYaw = 0;

// --- Three.js Setup ---
const scene = new THREE.Scene();
scene.background = new THREE.Color(0xdddddd);

const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
const resetCamera = () => {
    camera.position.set(0, 1, 10);
    camera.rotation.set(0, 0, 0, 'YXZ');
    camPitch = 0;
    camYaw = 0;
};
resetCamera();

const renderer = new THREE.WebGLRenderer({ antialias: true });
renderer.setSize(window.innerWidth, window.innerHeight);
document.body.appendChild(renderer.domElement);

const ambientLight = new THREE.AmbientLight(0xffffff, 0.5);
scene.add(ambientLight);
const dirLight = new THREE.DirectionalLight(0xffffff, 0.8);
dirLight.position.set(10, 20, 10);
scene.add(dirLight);

const baseGeometry = new THREE.BoxGeometry(1, 1, 1);
const textureLoader = new THREE.TextureLoader();

// Pointer Mesh (Red wireframe box for editors)
const pointerGeo = new THREE.BoxGeometry(1.1, 1.1, 1.1);
const pointerMat = new THREE.MeshBasicMaterial({ color: 0xff0000, wireframe: true });
editorPointer = new THREE.Mesh(pointerGeo, pointerMat);
scene.add(editorPointer);
editorPointer.visible = false;

// --- CodeMirror Editor Setup ---
const editorOptions = { mode: "javascript", theme: "dracula", lineNumbers: true, tabSize: 4 };
const editorDeclarations = CodeMirror.fromTextArea(document.getElementById('code-declarations'), editorOptions);
const editorUpdate = CodeMirror.fromTextArea(document.getElementById('code-update'), editorOptions);

// --- Bulletproof Input System ---
// We map strictly to physical hardware key codes (e.code) to ignore Caps Lock and keyboard layouts
window.Input = { 
    _keys: {}, 
    GetKey: function(k) { return !!this._keys[k]; } 
};
window.KeyCode = { 
    RightArrow: 'ArrowRight', LeftArrow: 'ArrowLeft', UpArrow: 'ArrowUp', DownArrow: 'ArrowDown',
    Space: 'Space', E: 'KeyE', Q: 'KeyQ', A: 'KeyA', Z: 'KeyZ', W: 'KeyW', S: 'KeyS', D: 'KeyD', R: 'KeyR', F: 'KeyF' 
};

window.addEventListener('keydown', e => {
    // Forcefully stop Spacebar from scrolling or clicking focused buttons
    if (e.code === 'Space') e.preventDefault();
    
    // Unlock the pointer manually if Q is pressed
    if (e.code === 'KeyQ' && document.pointerLockElement) {
        document.exitPointerLock();
    }

    // Register the physical hardware key pressed
    window.Input._keys[e.code] = true;
});

window.addEventListener('keyup', e => {
    window.Input._keys[e.code] = false;
});

// --- Mouse Look (Pointer Lock) ---
renderer.domElement.addEventListener('mousedown', () => {
    if (objectEditorMode) {
        document.body.requestPointerLock();
    }
});

document.addEventListener('pointerlockchange', () => {
    mouseLookEnabled = document.pointerLockElement === document.body;
    document.getElementById('crosshair').style.display = (mouseLookEnabled && objectEditorMode) ? 'block' : 'none';
});

document.addEventListener('mousemove', (e) => {
    if (mouseLookEnabled && objectEditorMode) {
        const sensitivity = 0.002;
        camYaw -= e.movementX * sensitivity;
        camPitch -= e.movementY * sensitivity;
        
        // Prevent the camera from flipping upside down
        camPitch = Math.max(-Math.PI/2, Math.min(Math.PI/2, camPitch));
        camera.rotation.set(camPitch, camYaw, 0, 'YXZ');
    }
});

// --- Ported 3DPL Functions ---
window.Color = { white: 0xffffff, black: 0x000000, blue: 0x0000ff, green: 0x00ff00, red: 0xff0000 };
window.Time = { deltaTime: 0 };
window.Mathf = Math;

window.qb = function(name, x, y, z) {
    const material = new THREE.MeshStandardMaterial({ color: 0x888888, transparent: true, opacity: 1.0 });
    const mesh = new THREE.Mesh(baseGeometry, material);
    mesh.position.set(x, y, z);
    mesh.name = name;
    scene.add(mesh);
    cubes.push(mesh);
    return mesh;
};

window.cl = function(name, colorHex) {
    cubes.forEach(c => { if (c.name === name) c.material.color.setHex(colorHex); });
};

window.alpha = function(name, alphaVal) {
    cubes.forEach(c => { if (c.name === name) c.material.opacity = alphaVal; });
};

window.dl = function(name) {
    for (let i = cubes.length - 1; i >= 0; i--) {
        if (cubes[i].name === name) {
            scene.remove(cubes[i]);
            cubes[i].material.dispose();
            cubes.splice(i, 1);
        }
    }
};

window.cs = function() {
    cubes.forEach(c => { scene.remove(c); c.material.dispose(); });
    cubes = [];
    vars = {};
};

function evalDeclarations() {
    window.cs();
    try { eval(editorDeclarations.getValue()); } catch (e) { console.error("Declaration Error:", e); }
}

function generateId() {
    return 'cube_' + Math.random().toString(36).substr(2, 9);
}

// --- Main Loop ---
function animate() {
    requestAnimationFrame(animate);
    const dt = clock.getDelta();
    window.Time.deltaTime = dt;
    actionCooldown -= dt;

    if (isExecuting) {
        try { eval(editorUpdate.getValue()); } catch (e) { isExecuting = false; console.error("Update Error:", e); }
    }

    // --- Object Editor Logic ---
    if (objectEditorMode) {
        editorPointer.visible = true;
        
        // Fly camera controls
        const moveSpeed = 10 * dt;
        const rotSpeed = 2 * dt;
        
        // WASD translations relative to where the camera is facing
        if (window.Input.GetKey(window.KeyCode.W)) camera.translateZ(-moveSpeed);
        if (window.Input.GetKey(window.KeyCode.S)) camera.translateZ(moveSpeed);
        if (window.Input.GetKey(window.KeyCode.A)) camera.translateX(-moveSpeed);
        if (window.Input.GetKey(window.KeyCode.D)) camera.translateX(moveSpeed);
        if (window.Input.GetKey(window.KeyCode.R)) camera.translateY(moveSpeed);
        if (window.Input.GetKey(window.KeyCode.F)) camera.translateY(-moveSpeed);
        
        // Keyboard rotation fallback
        if (window.Input.GetKey(window.KeyCode.LeftArrow)) camera.rotateY(rotSpeed);
        if (window.Input.GetKey(window.KeyCode.RightArrow)) camera.rotateY(-rotSpeed);
        if (window.Input.GetKey(window.KeyCode.UpArrow)) camera.rotateX(rotSpeed);
        if (window.Input.GetKey(window.KeyCode.DownArrow)) camera.rotateX(-rotSpeed);

        // Put pointer 4 units in front of camera, snapped to grid
        const forward = new THREE.Vector3(0, 0, -4);
        forward.applyMatrix4(camera.matrixWorld);
        editorPointer.position.set(
            Math.round(forward.x),
            Math.round(forward.y),
            Math.round(forward.z)
        );

        // Place and Erase (checks cooldown to prevent placing 60 blocks a second)
        if (actionCooldown <= 0) {
            if (window.Input.GetKey(window.KeyCode.Space)) {
                const id = generateId();
                const c = window.qb(id, editorPointer.position.x, editorPointer.position.y, editorPointer.position.z);
                
                const r = document.getElementById('obj-r').value / 255;
                const g = document.getElementById('obj-g').value / 255;
                const b = document.getElementById('obj-b').value / 255;
                const a = document.getElementById('obj-a').value;
                
                c.material.color.setRGB(r, g, b);
                c.material.opacity = parseFloat(a);
                
                console.log("Placed block:", id, "at", c.position); // Debug check
                actionCooldown = 0.25; // wait a quarter second before allowing next place
            }
            if (window.Input.GetKey(window.KeyCode.E)) {
                for (let i = cubes.length - 1; i >= 0; i--) {
                    if (cubes[i].position.distanceTo(editorPointer.position) < 0.5) {
                        console.log("Erased block:", cubes[i].name);
                        window.dl(cubes[i].name);
                        break;
                    }
                }
                actionCooldown = 0.25;
            }
        }
    }

    // --- Map Editor Logic ---
    if (mapEditorMode) {
        editorPointer.visible = true;

        const panSpeed = 20 * dt;
        if (window.Input.GetKey(window.KeyCode.W)) camera.position.z -= panSpeed;
        if (window.Input.GetKey(window.KeyCode.S)) camera.position.z += panSpeed;
        if (window.Input.GetKey(window.KeyCode.A)) camera.position.x -= panSpeed;
        if (window.Input.GetKey(window.KeyCode.D)) camera.position.x += panSpeed;

        if (actionCooldown <= 0) {
            if (window.Input.GetKey(window.KeyCode.UpArrow)) { editorPointer.position.z -= 1; actionCooldown = 0.15; }
            if (window.Input.GetKey(window.KeyCode.DownArrow)) { editorPointer.position.z += 1; actionCooldown = 0.15; }
            if (window.Input.GetKey(window.KeyCode.LeftArrow)) { editorPointer.position.x -= 1; actionCooldown = 0.15; }
            if (window.Input.GetKey(window.KeyCode.RightArrow)) { editorPointer.position.x += 1; actionCooldown = 0.15; }

            if (window.Input.GetKey(window.KeyCode.Space)) {
                window.qb(generateId(), editorPointer.position.x, 0, editorPointer.position.z);
                console.log("Map Editor: Placed block");
                actionCooldown = 0.25;
            }
            if (window.Input.GetKey(window.KeyCode.E)) {
                for (let i = cubes.length - 1; i >= 0; i--) {
                    if (cubes[i].position.distanceTo(editorPointer.position) < 0.5) {
                        window.dl(cubes[i].name);
                        break;
                    }
                }
                actionCooldown = 0.25;
            }
        }
    }

    renderer.render(scene, camera);
}

// --- Menu Navigation ---
const hideAllMenus = () => {
    // Unfocus any active input or button elements to reset spacebar flow completely
    if (document.activeElement) document.activeElement.blur();
    
    document.querySelectorAll('.menu-panel').forEach(el => el.style.display = 'none');
    objectEditorMode = false;
    mapEditorMode = false;
    editorPointer.visible = false;
    isExecuting = false;
    document.getElementById('crosshair').style.display = 'none';
    if (document.pointerLockElement) document.exitPointerLock();
};

document.getElementById('btn-creative').onclick = () => {
    hideAllMenus();
    document.getElementById('creative-menu').style.display = 'block';
};

document.getElementById('btn-3dpl').onclick = () => {
    hideAllMenus();
    document.getElementById('ide-panel').style.display = 'flex';
    setTimeout(() => { editorDeclarations.refresh(); editorUpdate.refresh(); }, 10);
    resetCamera();
};

document.getElementById('btn-obj-editor').onclick = () => {
    hideAllMenus();
    document.getElementById('obj-editor-ui').style.display = 'flex';
    window.cs();
    resetCamera();
    
    const axis = window.qb("AxisPoint", 0, 0, 0);
    axis.material.color.setHex(0x00ff00);
    axis.material.opacity = 0.5;

    objectEditorMode = true;
};

document.getElementById('btn-map-editor').onclick = () => {
    hideAllMenus();
    document.getElementById('map-editor-ui').style.display = 'flex';
    window.cs();
    
    camera.position.set(0, 50, 0);
    camera.rotation.set(-Math.PI / 2, 0, 0, 'YXZ');
    editorPointer.position.set(0, 0, 0);
    
    mapEditorMode = true;
};

const backToMain = () => {
    hideAllMenus();
    window.cs();
    document.getElementById('main-menu').style.display = 'block';
};
document.getElementById('btn-back-main').onclick = backToMain;
document.getElementById('btn-exit-obj').onclick = backToMain;
document.getElementById('btn-exit-map').onclick = backToMain;
document.getElementById('btn-exit-ide').onclick = backToMain;

// IDE Controls
document.getElementById('btn-run').onclick = () => {
    isExecuting = !isExecuting;
    if (!isExecuting) {
        resetCamera();
        evalDeclarations();
    }
};

editorDeclarations.on('change', () => { if (!isExecuting) evalDeclarations(); });

window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});

animate();
import * as THREE from 'three';

// --- State Variables ---
let cubes = [];
let vars = {}; // Global variables object for the user
let sounds = {}; // Dictionary to store loaded audio objects
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

// Audio Setup
const listener = new THREE.AudioListener();
camera.add(listener);
const audioLoader = new THREE.AudioLoader();

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

// --- Debug UI Helpers ---
const setDebugError = (msg) => {
    const consoleEl = document.getElementById('debug-console');
    if(consoleEl) consoleEl.innerHTML = `<span style="color: #ff5555;">${msg}</span>`;
};

const clearDebug = () => {
    const consoleEl = document.getElementById('debug-console');
    if(consoleEl) consoleEl.innerHTML = `<span style="color: #55ff55;">Running OK...</span>`;
};

// --- Bulletproof Input System ---
window.Input = { 
    _keys: {}, 
    GetKey: function(k) { return !!this._keys[k]; } 
};
window.KeyCode = { 
    RightArrow: 'ArrowRight', LeftArrow: 'ArrowLeft', UpArrow: 'ArrowUp', DownArrow: 'ArrowDown',
    Space: 'Space', E: 'KeyE', Q: 'KeyQ', A: 'KeyA', Z: 'KeyZ', W: 'KeyW', S: 'KeyS', D: 'KeyD', R: 'KeyR', F: 'KeyF' 
};

window.addEventListener('keydown', e => {
    const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
    const isTyping = activeTag === 'input' || activeTag === 'textarea';

    if (e.code === 'Space' && !isTyping) e.preventDefault();
    if (e.code === 'KeyQ' && document.pointerLockElement) document.exitPointerLock();
    
    window.Input._keys[e.code] = true;
});

window.addEventListener('keyup', e => {
    window.Input._keys[e.code] = false;
});

// --- Mouse Look (Pointer Lock) ---
renderer.domElement.addEventListener('mousedown', () => {
    if (objectEditorMode) document.body.requestPointerLock();
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
        camPitch = Math.max(-Math.PI/2, Math.min(Math.PI/2, camPitch));
        camera.rotation.set(camPitch, camYaw, 0, 'YXZ');
    }
});

// --- Ported 3DPL Functions ---
window.Color = { white: 0xffffff, black: 0x000000, blue: 0x0000ff, green: 0x00ff00, red: 0xff0000, yellow: 0xffff00 };
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

window.tx = function(name, textureName, source, wrap) {
    const mapTexture = textureLoader.load('Textures/' + textureName, undefined, undefined, (err) => {
        setDebugError(`Texture load error: Could not load Textures/${textureName}`);
    });
    mapTexture.colorSpace = THREE.SRGBColorSpace; 
    cubes.forEach(c => { 
        if (c.name === name) {
            c.material.map = mapTexture;
            c.material.needsUpdate = true;
        }
    });
};

// Transforms - Adjusted to map Unity's Local space and Z-Forward to Three.js
window.mv = function(name, x, y, z) {
    if (name === "camera") {
        camera.translateX(x); 
        camera.translateY(y); 
        camera.translateZ(-z); // Three.js uses -Z for forward
        return;
    }
    cubes.forEach(c => { 
        if (c.name === name) {
            c.translateX(x); 
            c.translateY(y); 
            c.translateZ(-z);
        }
    });
};

window.sp = function(name, x, y, z) {
    if (name === "camera") { camera.position.set(x, y, z); return; }
    cubes.forEach(c => { if (c.name === name) c.position.set(x, y, z); });
};
window.cp = window.sp;

window.rt = function(name, x, y, z) {
    const radX = x * (Math.PI / 180);
    const radY = y * (Math.PI / 180);
    const radZ = z * (Math.PI / 180);
    if (name === "camera") {
        camera.rotateX(radX); 
        camera.rotateY(radY); 
        camera.rotateZ(radZ);
        return;
    }
    cubes.forEach(c => {
        if (c.name === name) {
            c.rotateX(radX); 
            c.rotateY(radY); 
            c.rotateZ(radZ);
        }
    });
};

window.an = function(name, x, y, z) {
    const radX = x * (Math.PI / 180);
    const radY = y * (Math.PI / 180);
    const radZ = z * (Math.PI / 180);
    if (name === "camera") { camera.rotation.set(radX, radY, radZ, 'YXZ'); return; }
    cubes.forEach(c => {
        if (c.name === name) c.rotation.set(radX, radY, radZ, 'YXZ');
    });
};

window.sc = function(name, x, y, z) {
    cubes.forEach(c => { if (c.name === name) c.scale.set(x, y, z); });
};

// State & Group Collision (Checks ALL objects sharing a name)
window.ex = function(name) {
    return cubes.some(c => c.name === name);
};

window.eq = function(nameA, nameB) {
    const objA = cubes.find(c => c.name === nameA);
    if (!objA) return;
    
    if (nameB === "camera") {
        objA.position.copy(camera.position);
        objA.rotation.copy(camera.rotation);
    } else {
        const objB = cubes.find(c => c.name === nameB);
        if (objB) {
            objA.position.copy(objB.position);
            objA.rotation.copy(objB.rotation);
        }
    }
};

window.cd = function(nameA, nameB) {
    let objsA = nameA === "camera" ? [camera] : cubes.filter(c => c.name === nameA);
    let objsB = nameB === "camera" ? [camera] : cubes.filter(c => c.name === nameB);
    
    if (objsA.length === 0 || objsB.length === 0) return false;

    // Iterate through all objects of group A and check against all objects of group B
    for (let a of objsA) {
        let boxA = nameA === "camera" ? new THREE.Box3().setFromCenterAndSize(camera.position, new THREE.Vector3(1,1,1)) : new THREE.Box3().setFromObject(a);
        for (let b of objsB) {
            let boxB = nameB === "camera" ? new THREE.Box3().setFromCenterAndSize(camera.position, new THREE.Vector3(1,1,1)) : new THREE.Box3().setFromObject(b);
            if (boxA.intersectsBox(boxB)) return true;
        }
    }
    return false;
};

// Deletion
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
    
    Object.values(sounds).forEach(s => {
        if (s.isPlaying) s.stop();
    });
    sounds = {};
};

// --- PreProcessor ---
function PreProcessor(code) {
    let processed = code;
    processed = processed.replace(/(for\s*\([^)]+\)\s*)([^{\s][^;]+;?)/g, "$1 { $2 }");
    processed = processed.replace(/(while\s*\([^)]+\)\s*)([^{\s][^;]+;?)/g, "$1 { $2 }");

    const loopCheck = `if (performance.now() - window._evalStartTime > 500) { throw new Error("Loop timeout! Infinite loop prevented."); } `;
    processed = processed.replace(/(for\s*\(.*?\)\s*\{)/g, "$1 " + loopCheck);
    processed = processed.replace(/(while\s*\(.*?\)\s*\{)/g, "$1 " + loopCheck);

    return processed;
}

function evalDeclarations() {
    window.cs();
    window._evalStartTime = performance.now();
    clearDebug();
    resetCamera();
    
    try { 
        const rawDecl = editorDeclarations.getValue();
        const safeCode = PreProcessor(rawDecl);
        eval(safeCode); 
    } catch (e) { 
        if (e instanceof SyntaxError) {
            setDebugError("Syntax Error: " + e.message);
        } else {
            setDebugError("Declaration Error: " + e.message); 
            console.error(e);
        }
    }
}

// --- ALL 18 ORIGINAL TUTORIALS ---
const tutorials = {
    1: { decl: `//How to make a cube\nqb("mycube",0,0,0);`, upd: `` },
    2: { decl: `//Now change the coordinates of mycube so you can see it moves aroud\nqb("mycube",2,0,1);`, upd: `` },
    3: { decl: `//Let's make 3 cubes\nqb("a",0,0,0);\nqb("b",2,1,1);\nqb("c",-2,-1,-1);`, upd: `` },
    4: { decl: `//Let's color the cubes\nqb("a",0,0,0);\nqb("b",2,1,1);\nqb("c",-2,-1,-1);\ncl("a", Color.red);\ncl("b", Color.green);\ncl("c", Color.blue);`, upd: `` },
    5: { decl: `qb("a",0,0,0);\nqb("b",2,1,1);\nqb("c",-2,-1,-1);\ncl("a", Color.red);\ncl("b", Color.green);\ncl("c", Color.blue);`, upd: `//Let's rotate the cubes (scaled slightly for modern 60fps)\nrt("a",2,2,2);\nrt("b",-2,-2,-2);\nrt("c",2,2,2);\n//click on start!` },
    6: { decl: `qb("a",0,0,0);\nqb("b",2,1,1);\nqb("c",-2,-1,-1);\ncl("a", Color.red);\ncl("b", Color.green);\ncl("c", Color.blue);`, upd: `//Let's move the cubes\nmv("a",0.05,0,0);\nmv("b",0,0.05,0);\nmv("c",0,0,0.05);\n//click on start!` },
    7: { decl: `qb("a",0,0,0);\nqb("b",2,1,1);\nqb("c",-2,-1,-1);\ncl("a", Color.red);\ncl("b", Color.green);\ncl("c", Color.blue);`, upd: `//Move and rotate\nmv("a",0.05,0,0);\nrt("b",2,2,2);\nmv("c",0,0,0.05);\n//click on start!` },
    8: { decl: `qb("a",0,0,0);\nqb("b",2,1,1);\nqb("c",-2,-1,-1);\ncl("a", Color.red);\ncl("b", Color.green);\ncl("c", Color.blue);`, upd: `//Rotate\nrt("a",2,2,2);\nrt("b",-2,-2,-2);\nrt("c",2,2,2);\n//Move\nmv("a",0.05,0,0);\nmv("b",0,0.05,0);\nmv("c",0,0,0.05);\n//click on start!` },
    9: { decl: `//for loops\nfor(var x=0;x<4;x++) {\n    qb("a",x,x,x);\n}`, upd: `` },
    10: { decl: `//Functions\nfunction f(y) {//makes a bar\n    for(var x=0;x<6;x++) { qb("a",x,y,0); }\n}\nf(1);f(3);f(5);`, upd: `` },
    11: { decl: `//Collision Detection\nqb("a",3,0,0);\nqb("b",0,0,0);\ncl("a", Color.blue);\ncl("b", Color.red);`, upd: `//Move until crash\nif(!cd("a","b")) {\n    mv("b",0.05,0,0);\n}` },
    12: { decl: `//Moving camera\nfor(var x=0;x<4;x++) {\n    qb("a",x,x,x);\n}`, upd: `mv("camera",0.05,0.05,0.05);` },
    13: { decl: `//Rotating camera\nfor(var x=0;x<4;x++) {\n    qb("a",x,x,x);\n}`, upd: `rt("camera",1,1,1);` },
    14: { decl: `//Deleting an object\nqb("a",3,0,0);\nqb("b",0,0,0);\ncl("a", Color.blue);\ncl("b", Color.red);`, upd: `//Move until crash\nif(!cd("a","b")) {\n    mv("b",0.05,0,0);\n} else { \n    dl("a");\n}` },
    
    // --- UPDATED TUTORIALS ---
    15: {
        decl: `//Input Detection\nqb("a",3,0,0);`,
        upd: `//Use arrow keys and [A] and [Z]\nif (Input.GetKey (KeyCode.RightArrow))\n    mv("a",0.2,0,0);\nif (Input.GetKey (KeyCode.LeftArrow))\n    mv("a",-0.2,0,0);\nif (Input.GetKey (KeyCode.UpArrow))\n    mv("a",0,0.2,0);\nif (Input.GetKey (KeyCode.DownArrow))\n    mv("a",0,-0.2,0);\nif (Input.GetKey (KeyCode.A))\n    mv("a",0,0,0.2);\nif (Input.GetKey (KeyCode.Z))\n    mv("a",0,0,-0.2);`
    },
    16: {
        decl: `//Blockout\nvar depth=0;\nfor(var x=-15;x<20;x++) {\n    qb("topwall",x,10,depth);\n}\nfor(var y=10;y>-10;y--) {\n    qb("sidewall",-15,y,depth);\n}\nfor(var y=10;y>-10;y--) {\n    qb("sidewall",20,y,depth);\n}\nfor(var x=-15;x<21;x++) {\n    qb("bottomwall",x,-10,depth);\n}\nfunction block(name,x_start,y,color) {\n   var b_depth=0;\n   for(var bx=x_start;bx<x_start+5;bx++) {\n       qb(name,bx,y,b_depth);\n    }\n    cl(name, color);\n}\n\nqb("ball",-3,-6,depth);\nblock("paddle",-3,-8, Color.white);\n\nvar xoffset=-14;\nblock("block0",xoffset,7, Color.red);\nxoffset=xoffset+5;\nblock("block1",xoffset,7, Color.blue);\nxoffset=xoffset+5;\nblock("block2",xoffset,7, Color.green);\nxoffset=5;\nblock("block3",xoffset,4, Color.red);\nxoffset=xoffset+5;\nblock("block4",xoffset,4, Color.blue);\nxoffset=xoffset+5;\nblock("block5",xoffset,4, Color.green);\n\nvars["xspeed"]=0.1;\nvars["yspeed"]=0.1;\n\n// Reposition camera so we can see the board in Three.js\nsp("camera", 2.5, 0, 30);\nan("camera", 0, 0, 0);`,
        upd: `if (Input.GetKey (KeyCode.RightArrow))\n    mv("paddle",0.3,0,0);\nif (Input.GetKey (KeyCode.LeftArrow))\n    mv("paddle",-0.3,0,0);\nif(cd("ball","topwall")) {\n    vars["yspeed"]=-Math.abs(vars["yspeed"]); // Force bounce down\n}\nif(cd("ball","bottomwall")) {\n    vars["yspeed"]=Math.abs(vars["yspeed"]); // Force bounce up\n}\nif(cd("ball","sidewall")) {\n    vars["xspeed"]=-vars["xspeed"];\n}\nif(cd("ball","paddle")) {\n    vars["yspeed"]=Math.abs(vars["yspeed"]);\n}\nmv("ball",vars["xspeed"],vars["yspeed"],0);\nif(cd("ball","block0")) {\n    vars["yspeed"]=-vars["yspeed"];\n    dl("block0");\n}\nif(cd("ball","block1")) {\n    vars["yspeed"]=-vars["yspeed"];\n    dl("block1");\n}\nif(cd("ball","block2")) {\n    vars["yspeed"]=-vars["yspeed"];\n    dl("block2");\n}\nif(cd("ball","block3")) {\n    vars["yspeed"]=-vars["yspeed"];\n    dl("block3");\n}\nif(cd("ball","block4")) {\n    vars["yspeed"]=-vars["yspeed"];\n    dl("block4");\n}\nif(cd("ball","block5")) {\n    vars["yspeed"]=-vars["yspeed"];\n    dl("block5");\n}`
    },
    17: {
        decl: `//Space travel\nfunction big_asteroid(name,x,y,z,color) {\n    qb(name,x,y,z);\n    qb(name,x-1,y,z);\n    qb(name,x,y-1,z);\n    qb(name,x,y,z-1);\n    qb(name,x+1,y,z);\n    qb(name,x,y+1,z);\n    qb(name,x,y,z+1);\n    cl(name, color);\n}\n//Asteroids\nbig_asteroid("Asteroid0",7,7,7, Color.red);\nbig_asteroid("Asteroid1",-7,-7,-7, Color.blue);\nbig_asteroid("Asteroid2",14,14,14, Color.green);\nbig_asteroid("Asteroid3",-14,-14,-14, Color.yellow);\nsp("camera", 0, 0, 0); // Start in center`,
        upd: `//Use arrow keys and [A] and [Z] and Space\nif (Input.GetKey (KeyCode.RightArrow))\n    rt("camera",0,2,0);\nif (Input.GetKey (KeyCode.LeftArrow))\n    rt("camera",0,-2,0);\nif (Input.GetKey (KeyCode.UpArrow))\n    rt("camera",2,0,0);\nif (Input.GetKey (KeyCode.DownArrow))\n    rt("camera",-2,0,0);\nif (Input.GetKey (KeyCode.A))\n    mv("camera",0,0,0.5);\nif (Input.GetKey (KeyCode.Z))\n    mv("camera",0,0,-0.5);\nif (Input.GetKey (KeyCode.Space)) {\n    qb("bullet",0,0,0);\n    eq("bullet","camera"); // Takes rotation so local mv works\n}\nif (ex("bullet")) {\n   mv("bullet",0,0,0.5);\n}\nif(cd("bullet","Asteroid0")) \n    dl("Asteroid0");\nif(cd("bullet","Asteroid1")) \n    dl("Asteroid1");\nif(cd("bullet","Asteroid2")) \n    dl("Asteroid2");\nif(cd("bullet","Asteroid3")) \n    dl("Asteroid3");`
    },
    18: {
        decl: `//First person 3D platformer\n// Optimized floor to avoid lagging web browsers with 400 cubes\nqb("floor", 0, -5, 0);\nsc("floor", 40, 1, 40);\ncl("floor", Color.green);\n\n//trees\nfunction drawtree(x,y,z) {\n    var Brown = 0x8b45be; //Translated from Color(139,69,190)\n    qb("trunk",x,y,z);\n    qb("trunk",x,y+1,z);\n    qb("trunk",x,y+2,z);\n    qb("trunk",x,y+3,z);\n    cl("trunk", Brown);\n    qb("bush",x,y+4,z);\n    qb("bush",x+1,y+4,z);\n    qb("bush",x-1,y+4,z);\n    cl("bush", Color.green);\n}\n//trees in front\ndrawtree(0,-4,0);\ndrawtree(5,-4,5);\ndrawtree( -5,-4,15);\n//trees behind\ndrawtree(-3,-4,-10);\ndrawtree(-10,-4,-10);\ndrawtree(5,-4,-15);\n\nvars["gforce"]=-0.1;\nvars["isJumping"]=false;`,
        upd: `//Use arrow keys to move, Space to jump\n//Implement gravity\nif(!cd("camera","floor") && !cd("camera","bush") && !vars["isJumping"]) {\n    mv("camera",0,vars["gforce"],0);\n}\nvars["isJumping"] = false;\n\n//Get user input\nif (Input.GetKey (KeyCode.RightArrow))\n    rt("camera",0,2,0);\nif (Input.GetKey (KeyCode.LeftArrow))\n    rt("camera",0,-2,0);\nif (Input.GetKey (KeyCode.Space)) {\n    mv("camera",0,0.3,0);\n    vars["isJumping"] = true;\n}\nif (Input.GetKey (KeyCode.UpArrow) && !cd("camera","trunk"))\n    mv("camera",0,0,0.2);\nif (Input.GetKey (KeyCode.DownArrow))\n    mv("camera",0,0,-0.2);`
    }
};

// Dynamically generate the 18 buttons in the UI (Removing old textual labels)
const tutContainer = document.querySelector('.tutorial-row');
tutContainer.innerHTML = ''; // Wipes out hardcoded "Tut 1: Basics" buttons

for (let i = 1; i <= 18; i++) {
    const btn = document.createElement('button');
    btn.id = `tut-${i}`;
    btn.innerText = `${i}`;
    btn.onclick = () => loadTutorial(i);
    tutContainer.appendChild(btn);
}

const loadTutorial = (num) => {
    isExecuting = false;
    document.getElementById('debug-console').innerHTML = `<span style="color: #aaaaaa;">Stopped.</span>`;
    editorDeclarations.setValue(tutorials[num].decl);
    editorUpdate.setValue(tutorials[num].upd);
    evalDeclarations();
};


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
        window._evalStartTime = performance.now();
        try { 
            const updCode = editorUpdate.getValue();
            const safeUpdCode = PreProcessor(updCode);
            eval(safeUpdCode); 
        } catch (e) { 
            isExecuting = false; 
            setDebugError("Update Error: " + e.message + "<br><em>Execution stopped.</em>");
            console.error("Update Error:", e); 
        }
    }

    // --- Object Editor Logic ---
    if (objectEditorMode) {
        editorPointer.visible = true;
        
        const moveSpeed = 10 * dt;
        const rotSpeed = 2 * dt;
        
        if (window.Input.GetKey(window.KeyCode.W)) camera.translateZ(-moveSpeed);
        if (window.Input.GetKey(window.KeyCode.S)) camera.translateZ(moveSpeed);
        if (window.Input.GetKey(window.KeyCode.A)) camera.translateX(-moveSpeed);
        if (window.Input.GetKey(window.KeyCode.D)) camera.translateX(moveSpeed);
        if (window.Input.GetKey(window.KeyCode.R)) camera.translateY(moveSpeed);
        if (window.Input.GetKey(window.KeyCode.F)) camera.translateY(-moveSpeed);
        
        if (window.Input.GetKey(window.KeyCode.LeftArrow)) camera.rotateY(rotSpeed);
        if (window.Input.GetKey(window.KeyCode.RightArrow)) camera.rotateY(-rotSpeed);
        if (window.Input.GetKey(window.KeyCode.UpArrow)) camera.rotateX(rotSpeed);
        if (window.Input.GetKey(window.KeyCode.DownArrow)) camera.rotateX(-rotSpeed);

        const forward = new THREE.Vector3(0, 0, -4);
        forward.applyMatrix4(camera.matrixWorld);
        editorPointer.position.set(Math.round(forward.x), Math.round(forward.y), Math.round(forward.z));

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

document.getElementById('btn-run').onclick = () => {
    isExecuting = !isExecuting;
    
    // Resume audio context to bypass browser auto-play restrictions on interaction
    if (listener.context.state === 'suspended') {
        listener.context.resume();
    }

    if (!isExecuting) {
        document.getElementById('debug-console').innerHTML = `<span style="color: #aaaaaa;">Stopped.</span>`;
        // Only run declarations if the engine was completely stopped to avoid wiping out the game state
        evalDeclarations(); 
    } else {
        clearDebug();
    }
};

editorDeclarations.on('change', () => { if (!isExecuting) evalDeclarations(); });

window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});

animate();
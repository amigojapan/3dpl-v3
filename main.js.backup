import * as THREE from 'three';

// --- State Variables ---
let cubes = [];
let vars = {}; // Global variables object for the user
let sounds = {}; // Dictionary to store loaded audio objects
let isExecuting = false;
let clock = new THREE.Clock();

// Object Cache
const loadedObjectCache = {};
const pendingObjectDataPromises = {};
const objectCollisionDataCache = new Map();
const mapRenderBatchStates = new WeakMap();
const objectVoxelCollisionData = new WeakMap();
const objectCollisionLocalBoxes = new WeakMap();

// Editor States
let objectEditorMode = false;
let mapEditorMode = false;
let editorPointer = null;
let actionCooldown = 0;
let selectedObjectTexture = "";
let selectedObjectColliderName = "";

// Mouse Look States
let mouseLookEnabled = false;
let camPitch = 0;
let camYaw = 0;

// --- Three.js Setup ---
const scene = new THREE.Scene();
const mapBuilderBackground = new THREE.Color(0xdddddd);
scene.background = mapBuilderBackground;

const camera = new THREE.PerspectiveCamera(75, window.innerWidth / window.innerHeight, 0.1, 1000);
const resetCamera = () => {
    camera.position.set(0, 1, 10);
    camera.rotation.set(0, 0, 0, 'YXZ');
    camera.far = 1000;
    camera.updateProjectionMatrix();
    camPitch = 0;
    camYaw = 0;
};
resetCamera();

// Audio Setup
const listener = new THREE.AudioListener();
camera.add(listener);
const audioLoader = new THREE.AudioLoader();

const renderer = new THREE.WebGLRenderer({
    antialias: true,
    powerPreference: 'high-performance'
});
renderer.setSize(window.innerWidth, window.innerHeight);
renderer.domElement.tabIndex = 0;
document.body.appendChild(renderer.domElement);

const ambientLight = new THREE.AmbientLight(0xffffff, 0.5);
scene.add(ambientLight);
const dirLight = new THREE.DirectionalLight(0xffffff, 0.8);
dirLight.position.set(10, 20, 10);
scene.add(dirLight);

const baseGeometry = new THREE.BoxGeometry(1, 1, 1);
const textureLoader = new THREE.TextureLoader();
const sharedTextureCache = new Map();
let wrappedBaseGeometry = null;
let skyBackgroundTexture = null;

function imageHasTransparentPixels(image) {
    const width = image.naturalWidth || image.videoWidth || image.width;
    const height = image.naturalHeight || image.videoHeight || image.height;
    if (!width || !height) return true;

    try {
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d', { willReadFrequently: true });
        context.drawImage(image, 0, 0, width, height);
        const pixels = context.getImageData(0, 0, width, height).data;
        for (let offset = 3; offset < pixels.length; offset += 4) {
            if (pixels[offset] < 255) return true;
        }
        return false;
    } catch (error) {
        // A tainted or unsupported image stays on the conservative transparent
        // path, preserving its appearance rather than guessing.
        return true;
    }
}

function updateTextureMaterialTransparency(texture, material) {
    const hasTransparentPixels =
        texture.userData.threeDPLHasTransparentPixels;
    if (hasTransparentPixels === undefined) return;
    const needsTransparency = material.opacity < 1 || hasTransparentPixels;
    if (material.transparent !== needsTransparency) {
        material.transparent = needsTransparency;
        material.needsUpdate = true;
    }
}

function materialNeedsTransparency(material, opacity) {
    if (opacity < 1) return true;
    if (!material.map) return false;
    const hasTransparentPixels =
        material.map.userData.threeDPLHasTransparentPixels;
    return hasTransparentPixels === undefined ? true : hasTransparentPixels;
}

function assignSharedTexture(material, textureName) {
    const previousTexture = material.map;
    if (previousTexture && previousTexture.userData.threeDPLMaterials) {
        previousTexture.userData.threeDPLMaterials.delete(material);
    }

    const texture = loadSharedTexture(textureName);
    material.map = texture;
    if (texture.userData.threeDPLHasTransparentPixels === undefined) {
        if (!texture.userData.threeDPLMaterials) {
            texture.userData.threeDPLMaterials = new Set();
        }
        texture.userData.threeDPLMaterials.add(material);
        if (!material.transparent) {
            material.transparent = true;
            material.needsUpdate = true;
        }
    }
    updateTextureMaterialTransparency(texture, material);
    return texture;
}

function loadSharedTexture(textureName) {
    const textureUrl = 'Textures/' + textureName;
    if (sharedTextureCache.has(textureUrl)) {
        return sharedTextureCache.get(textureUrl);
    }

    let resolveTextureReady;
    const textureReady = new Promise(resolve => {
        resolveTextureReady = resolve;
    });
    const texture = textureLoader.load(
        textureUrl,
        loadedTexture => {
            loadedTexture.userData.threeDPLHasTransparentPixels =
                imageHasTransparentPixels(loadedTexture.image);
            const materials = loadedTexture.userData.threeDPLMaterials;
            if (materials) {
                materials.forEach(material =>
                    updateTextureMaterialTransparency(loadedTexture, material));
                materials.clear();
            }
            resolveTextureReady(loadedTexture);
        },
        undefined,
        () => {
            texture.userData.threeDPLHasTransparentPixels = true;
            if (texture.userData.threeDPLMaterials) {
                texture.userData.threeDPLMaterials.clear();
            }
            sharedTextureCache.delete(textureUrl);
            resolveTextureReady(texture);
            setDebugError(`Texture load error: Could not load ${textureUrl}`);
        }
    );
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.userData.threeDPLReady = textureReady;
    sharedTextureCache.set(textureUrl, texture);
    return texture;
}

function setSkyboxVisible(visible) {
    scene.background = (visible && skyBackgroundTexture)
        ? skyBackgroundTexture
        : mapBuilderBackground;
}

textureLoader.load(
    'Skyboxes/sunflowers_puresky_2k.jpg',
    texture => {
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.mapping = THREE.EquirectangularReflectionMapping;
        skyBackgroundTexture = texture;
        setSkyboxVisible(!mapEditorMode);
    },
    undefined,
    error => {
        console.error('Skybox load error:', error);
        setDebugError('Skybox load error: Skyboxes/sunflowers_puresky_2k.jpg');
    }
);

function get_object_position(target) {
    if (!target) return null;
    
    // If target is a string ID, look it up in vars
    if (typeof target === 'string') {
        if (vars[target] && vars[target].transform) {
            return vars[target].transform.position;
        }
        return null;
    }
    
    // If target is an object reference
    if (target.transform && target.transform.position) {
        return target.transform.position;
    }
    if (target.position) {
        return target.position;
    }
    
    return null;
}

/**
 * Resolves the string name identifier required for built-in collision checks like cd().
 */
function get_object_name(target, fallbackName) {
    if (typeof target === 'string') return target;
    if (target && target.name) return target.name;
    
    // Search the global vars map for a matching object reference
    for (var key in vars) {
        if (vars[key] === target) return key;
    }
    
    return fallbackName || "";
}

/**
 * Checks if an entity is colliding with solid voxel ground/map geometry.
 * Combines bounding box checks with height evaluation to prevent false collisions when airborne.
 * 
 * @param {Object|string} landObj - The map object or string identifier (e.g., vars["land"] or "land")
 * @param {Object|string} carObj - The moving entity or string identifier (e.g., vars["car"] or "car0")
 * @param {number} [heightThreshold=1.0] - Maximum Y-axis height offset to consider as ground contact
 * @returns {boolean} True if the entity is within ground altitude AND intersecting the map bounds
 */
function is_touching_voxel(landObj, colliderObj, colliderName) {
    var land = typeof landObj === "string" ? vars[landObj] : landObj;
    var colliders = typeof colliderObj === "string" ? vars[colliderObj] : colliderObj;

    if (!land || !colliders) {
        return false;
    }

    var collider = null;

    // Find ONLY the requested collider.
    colliders.traverse(function(obj) {
        if (obj.isMesh && obj.name === colliderName) {
            collider = obj;
        }
    });

    if (!collider) {
        return false;
    }

    collider.updateWorldMatrix(true, false);
    const colliderBox = new THREE.Box3().setFromObject(collider);
    return colliderMeshTouchesLand(land, colliderBox);
}

// --- 3DPL Texture UV Unwrapping ---
window.apply3DPLWrap1 = function(geometry) {
    const uvAttribute = geometry.attributes.uv;

    const setFace = (faceIdx, col, row) => {
        const w = 1/3, h = 1/2;
        const u = col * w, v = row * h;
        const idx = faceIdx * 4;

        uvAttribute.setXY(idx + 0, u, v + h);     
        uvAttribute.setXY(idx + 1, u + w, v + h); 
        uvAttribute.setXY(idx + 2, u, v);         
        uvAttribute.setXY(idx + 3, u + w, v);     
    };

    setFace(4, 0, 1); // Front  
    setFace(5, 1, 1); // Back   
    setFace(2, 2, 1); // Top    
    setFace(3, 0, 0); // Bottom 
    setFace(1, 1, 0); // Left   
    setFace(0, 2, 0); // Right  

    uvAttribute.needsUpdate = true;
};

function getWrappedBaseGeometry() {
    if (!wrappedBaseGeometry) {
        wrappedBaseGeometry = baseGeometry.clone();
        window.apply3DPLWrap1(wrappedBaseGeometry);
    }
    return wrappedBaseGeometry;
}

// Pointer Mesh
const pointerGeo = new THREE.BoxGeometry(1.1, 1.1, 1.1);
const pointerMat = new THREE.MeshBasicMaterial({ color: 0xff0000, wireframe: true });
editorPointer = new THREE.Mesh(pointerGeo, pointerMat);
scene.add(editorPointer);
editorPointer.visible = false;
const editorForwardPosition = new THREE.Vector3();

// --- CodeMirror Editor Setup ---
const editorOptions = { mode: "javascript", theme: "dracula", lineNumbers: true, tabSize: 4 };
const editorDeclarations = CodeMirror.fromTextArea(document.getElementById('code-declarations'), editorOptions);
const editorUpdate = CodeMirror.fromTextArea(document.getElementById('code-update'), editorOptions);

// Sets the name saved on newly placed Object Editor blocks. Using the same
// name for several blocks lets them act as one logical collider probe.
const objectColliderNameInput = document.getElementById('obj-collider-name');
const clearColliderNameButton = document.getElementById('btn-clear-collider-name');

window.SetColliderName = function(name) {
    selectedObjectColliderName = String(name || '').trim();
    objectColliderNameInput.value = selectedObjectColliderName;
    return selectedObjectColliderName;
};

objectColliderNameInput.oninput = () => {
    selectedObjectColliderName = objectColliderNameInput.value.trim();
};
clearColliderNameButton.onclick = () => window.SetColliderName('');

// --- Object Editor Texture Picker ---
const texturePicker = document.getElementById('texture-picker');
const textureGrid = document.getElementById('texture-grid');
const textureSearch = document.getElementById('texture-search');
const selectedTextureName = document.getElementById('selected-texture-name');
const selectTextureButton = document.getElementById('btn-select-texture');
const clearTextureButton = document.getElementById('btn-clear-texture');
const availableTextureNames = [
    "1flowercube.png",
    "16x16_1.png",
    "16x16_2.png",
    "16x16_3.png",
    "16x16_4.png",
    "16x16_5.png",
    "16x16_6.png",
    "16x16_7.png",
    "16x16_8.png",
    "16x16_9.png",
    "16x16_10.png",
    "16x16_11.png",
    "16x16_12.png",
    "16x16_13.png",
    "16x16_14.png",
    "16x16_15.png",
    "16x16_16.png",
    "16x16_17.png",
    "16x16_18.png",
    "16x16_newbrick.png",
    "16x16_newbrick1.png",
    "16x16_newbrick2.png",
    "16x16_newbrick3.png",
    "16x16_tree_leaves.png",
    "16x16_tree_leaves2.png",
    "16x16_tree_leaves3.png",
    "16x16_window.png",
    "16x16_wood.png",
    "32x32_grass1.png",
    "32x32_grass2.png",
    "32x32_grass3.png",
    "32x32_grass4.png",
    "32x32_grass5.png",
    "32x32_grass6.png",
    "32x32_grass7.png",
    "64x64_grass1.png",
    "64x64_grass2.png",
    "64x64_grassandcoconut.png",
    "64x64brick1.png",
    "64x64wood1.png",
    "64x64wood2.png",
    "64x64wood3.png",
    "64x64wood4.png",
    "beach_sand_with_bluestarfish.png",
    "beach_sand_with_butterfly.png",
    "beach_sand_with_butterfly1.png",
    "beach_sand_with_butterfly2.png",
    "beach_sand_with_crustacea1.png",
    "beach_sand_with_pinkstarfish.png",
    "beach_sand_with_shell.png",
    "beach_sand_with_shell1.png",
    "beach_sand_with_silverstarfish.png",
    "beach_sand.png",
    "beach_sand2.png",
    "coconut_wood.png",
    "coconut_wood2.png",
    "doorbtn.png",
    "doorbtn1.png",
    "doorsbuton1.png",
    "doorswitch1.png",
    "dotted_box.png",
    "dotted_box1.png",
    "dotted_box3.png",
    "dotted_box4.png",
    "dotted_box5.png",
    "dotted_box6.png",
    "dotted_box7.png",
    "dotted_box8.png",
    "dungeonkeyss.png",
    "fence2.png",
    "fenceA.png",
    "fenceback.png",
    "flower_right.png",
    "flower2.png",
    "grass.png",
    "grass1.png",
    "grass2.png",
    "grass3.png",
    "grass4.png",
    "grass5.png",
    "ground1.png",
    "ground2.png",
    "leafs1.png",
    "leafs2.png",
    "leafs3.png",
    "leafs4.png",
    "leafs5.png",
    "material1.png",
    "material2.png",
    "material3.png",
    "Medieval_cube.png",
    "new_flower.png",
    "newfence.png",
    "OneSIdeBark.png",
    "OneSideGreenery.png",
    "picasso.png",
    "red_vines.png",
    "room_lamp.png",
    "SixSideCube_Grass.png",
    "SixSideCube_Grass2.png",
    "SixSideCube_grass7.png",
    "SixSideCube_grass8.png",
    "SixSideCubeTemplate.jpg",
    "SixSideCubeTemplate1.png",
    "SixSideGround.png",
    "stone1.png",
    "stone2.png",
    "stone3.png",
    "stone4.png",
    "stonetype1.png",
    "stonetype2.png",
    "stonetype3.png",
    "stonetype4.png",
    "stonetype5.png",
    "stonetype6.png",
    "stonetype7.png",
    "stonetype8.png",
    "stonetype9.png",
    "stonetype10.png",
    "stonetype11.png",
    "stonetype12.png",
    "stonetype13.png",
    "stonetype14.png",
    "stonetype15.png",
    "stonetype16.png",
    "stonetype17.png",
    "stonetype18.png",
    "stonetype19.png",
    "stonetype20.png",
    "stonetype21a.png",
    "stonetype22.png",
    "stonetype23.png",
    "stonetype24.png",
    "stonetype25.png",
    "stonetype26.png",
    "stonetype27.png",
    "stonetype28.png",
    "stonetype29.png",
    "stonetype30.png",
    "stonetype31.png",
    "texture_wall3.png",
    "texture_wall4.png",
    "texture_wall6.png",
    "texture_wall7.png",
    "texture_wall8.png",
    "tree_leaves.png",
    "tree_leaves2.png",
    "tree_leaves3.png",
    "tree_wood.png",
    "Untitled-1.png",
    "water.png",
    "water2.png",
    "water3.png",
    "water4.png",
    "water5.png",
    "water6.png",
    "window1.png",
    "wood1.png",
    "wood2.png",
    "wood3.png",
    "wood4.png",
    "wood5.png",
    "wood7.png",
    "woodtype1.png",
    "woodtype2.png",
    "woodtype3.png",
    "woodtype4.png",
    "woodtype5.png",
    "woodtype6.png",
    "woodtype7.png",
    "woodtype8.png",
    "woodtype9.png",
    "woodtype10.png",
    "woodtype11.png",
    "working_fence.png"
];

function selectObjectEditorTexture(textureName) {
    selectedObjectTexture = textureName;
    selectedTextureName.textContent = textureName
        ? `Selected: ${textureName}`
        : 'Selected: None (color only)';
    selectTextureButton.textContent = textureName ? 'Change Texture' : 'Select Texture';
    textureGrid.querySelectorAll('.texture-option').forEach(option => {
        option.classList.toggle('selected', option.dataset.texture === textureName);
    });
    texturePicker.style.display = 'none';
}

function renderTextureChoices(filterText = '') {
    const filter = filterText.trim().toLowerCase();
    const fragment = document.createDocumentFragment();
    textureGrid.innerHTML = '';

    availableTextureNames
        .filter(textureName => textureName.toLowerCase().includes(filter))
        .forEach(textureName => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'texture-option';
            option.dataset.texture = textureName;
            option.title = textureName;
            option.classList.toggle('selected', textureName === selectedObjectTexture);

            const preview = document.createElement('img');
            preview.src = `Textures/${encodeURIComponent(textureName)}`;
            preview.alt = textureName;
            preview.loading = 'lazy';

            const label = document.createElement('span');
            label.textContent = textureName;
            option.append(preview, label);
            option.onclick = () => selectObjectEditorTexture(textureName);
            fragment.appendChild(option);
        });

    textureGrid.appendChild(fragment);
}

selectTextureButton.onclick = () => {
    const opening = texturePicker.style.display !== 'block';
    texturePicker.style.display = opening ? 'block' : 'none';
    if (opening) {
        renderTextureChoices(textureSearch.value);
        textureSearch.focus();
    }
};

clearTextureButton.onclick = () => selectObjectEditorTexture('');
textureSearch.oninput = () => renderTextureChoices(textureSearch.value);

renderTextureChoices();

// --- Bird's-eye Map Editor ---
const fallbackMapObjectNames = [
    "1tree.json",
    "3DLP_MAP_ISLAND_extended.json",
    "airplane_jet.json",
    "Airpor.json",
    "Airport.json",
    "airport_terrain_for_cloning.json",
    "big_building1.json",
    "Bridge_left_side.json",
    "Bridge_middle_for_clone.json",
    "buildings_area.json",
    "buildings_starting_terrain.json",
    "Camera_Coliders.json",
    "car_colliders.json",
    "cemetary.json",
    "circle.json",
    "cross.json",
    "Cute_character_creating_broken_heart.json",
    "door1.json",
    "door2.json",
    "door3.json",
    "doorsbuton1.json",
    "Dungeon.json",
    "Dungeon2.json",
    "dungeonkeys.json",
    "first_house.json",
    "Fountain_place.json",
    "Futuristic_Helycopter.json",
    "Futuristic_Helycopter_propeller.json",
    "Futuristic_Helycopter_without_wings.json",
    "Futuristic_Helycopter_without_wings_with_colliders.json",
    "heli_no_proeller.json",
    "helicpter_colliders.json",
    "house2.json",
    "house3.json",
    "house4.json",
    "houses_and_terrain.json",
    "houses_and_terrain1.json",
    "key_stone.json",
    "ladder_to_helly.json",
    "main_road.json",
    "main_road1.json",
    "new_road_a.json",
    "new_road_part_a_vertical.json",
    "PerspectiveTemplate.json",
    "picasso.json",
    "pillars.json",
    "propeller.json",
    "road_1.json",
    "road_a.json",
    "road_b.json",
    "road_part_a.json",
    "road_part_b.json",
    "roadj.json",
    "skyscraper1.json",
    "spider.json",
    "sport_car.json",
    "sport_car_tires.json",
    "sports-car-tires.json",
    "sports-car.json",
    "Station.json",
    "test_delete_this.json",
    "test_delete_this1.json",
    "TransparentTexturedCross.json",
    "trap1.json",
    "trap2.json",
    "Trees.json",
    "tutorial32_ground.json",
    "tutorial32_wall.json"
];
const previewPathForMapObject = filename =>
    `Preview/${filename.substring(filename.lastIndexOf('/') + 1)
        .replace(/\.json$/i, '')}.png`;
let availableMapObjects = fallbackMapObjectNames.map(filename => ({
    file: filename,
    label: filename,
    preview: previewPathForMapObject(filename)
}));
let mapObjectLibraryRevision = 'fallback-20260820';
let mapObjectLibraryMessage =
    `${availableMapObjects.length} built-in JSON objects available`;
let mapObjectLibraryMessageColor = '#aaaaaa';
let mapObjectPickerRenderId = 0;
let selectedMapObject = '';
let mapObjectPreview = null;
let mapEditorLoadToken = 0;
let pendingMapEditorObjects = [];

const mapObjectPicker = document.getElementById('map-object-picker');
const mapObjectGrid = document.getElementById('map-object-grid');
const mapObjectSearch = document.getElementById('map-object-search');
const mapObjectLibraryStatus = document.getElementById('map-object-library-status');
const closeMapObjectPickerButton = document.getElementById('btn-close-map-object-picker');
const selectedMapObjectLabel = document.getElementById('selected-map-object');
const selectMapObjectButton = document.getElementById('btn-select-map-object');
const mapObjectYInput = document.getElementById('map-object-y');
const mapObjectRotationInput = document.getElementById('map-object-rotation');
const mapObjectScaleInput = document.getElementById('map-object-scale');
const rotateMapObjectButton = document.getElementById('btn-rotate-map-object');
const centerMapPointerButton = document.getElementById('btn-center-map-pointer');
const mapObjectCount = document.getElementById('map-object-count');

function updateMapObjectCount() {
    const count = cubes.filter(object => object.userData.mapEditorObject).length;
    mapObjectCount.textContent = `Placed objects: ${count}`;
}

function removeMapObjectPreview() {
    if (!mapObjectPreview) return;
    cancelObjectLoads(mapObjectPreview);
    mapObjectPreview.removeFromParent();
    mapObjectPreview.traverse(child => {
        if (child.isInstancedMesh) child.dispose();
    });
    disposeObjectMaterials(mapObjectPreview);
    mapObjectPreview = null;
}

function discardPendingMapEditorObjects(objects = pendingMapEditorObjects) {
    objects.forEach(object => {
        cancelObjectLoads(object);
        object.removeFromParent();
        const cubeIndex = cubes.indexOf(object);
        if (cubeIndex !== -1) cubes.splice(cubeIndex, 1);
        disposeObjectMaterials(object);
    });
    if (objects === pendingMapEditorObjects) pendingMapEditorObjects = [];
}

function createMapObjectPreview() {
    removeMapObjectPreview();
    if (!mapEditorMode || !selectedMapObject) return;

    const previewName = `map_preview_${generateId()}`;
    mapObjectPreview = window.Obj(selectedMapObject, previewName, 0, 0, 0);

    // Loaders normally register objects as scene content. The preview is temporary.
    const cubeIndex = cubes.indexOf(mapObjectPreview);
    if (cubeIndex !== -1) cubes.splice(cubeIndex, 1);
    mapObjectPreview.userData.mapEditorPreview = true;
    syncMapObjectPreview();
}

function buildMapObjectPreviewBatch(previewObject) {
    if (previewObject.userData.mapPreviewBatch) return;

    const voxelMeshes = [];
    previewObject.traverse(child => {
        if (child.isMesh && !child.userData.mapPreviewBatch) {
            voxelMeshes.push(child);
        }
    });
    if (voxelMeshes.length === 0) return;

    previewObject.updateWorldMatrix(true, true);
    const inversePreviewMatrix = new THREE.Matrix4()
        .copy(previewObject.matrixWorld)
        .invert();
    const instanceMatrix = new THREE.Matrix4();
    const material = new THREE.MeshStandardMaterial({
        color: 0x00ffff,
        wireframe: true,
        transparent: true,
        opacity: 0.45,
        depthTest: false,
        depthWrite: false
    });
    const batch = new THREE.InstancedMesh(
        baseGeometry,
        material,
        voxelMeshes.length
    );

    voxelMeshes.forEach((mesh, index) => {
        instanceMatrix.multiplyMatrices(
            inversePreviewMatrix,
            mesh.matrixWorld
        );
        batch.setMatrixAt(index, instanceMatrix);
    });
    batch.instanceMatrix.needsUpdate = true;
    batch.computeBoundingBox();
    batch.computeBoundingSphere();
    batch.renderOrder = 999;
    batch.userData.mapPreviewBatch = true;

    voxelMeshes.forEach(mesh => {
        unindexCollisionObject(mesh);
        mesh.removeFromParent();
        const materials = Array.isArray(mesh.material)
            ? mesh.material
            : [mesh.material];
        materials.forEach(voxelMaterial => {
            if (voxelMaterial.map &&
                voxelMaterial.map.userData.threeDPLMaterials) {
                voxelMaterial.map.userData.threeDPLMaterials.delete(
                    voxelMaterial
                );
            }
            voxelMaterial.dispose();
        });
    });

    previewObject.add(batch);
    previewObject.userData.mapPreviewBatch = batch;
}

function syncMapObjectPreview() {
    if (!mapObjectPreview) return;

    const scale = Math.max(0.01, Number(mapObjectScaleInput.value) || 1);
    const rotationY = Number(mapObjectRotationInput.value) || 0;
    mapObjectPreview.visible = mapEditorMode;
    mapObjectPreview.position.copy(editorPointer.position);
    mapObjectPreview.scale.set(scale, scale, scale);
    mapObjectPreview.rotation.set(0, THREE.MathUtils.degToRad(-rotationY), 0, 'YXZ');

    if (mapObjectPreview.userData.loaded) {
        buildMapObjectPreviewBatch(mapObjectPreview);
    }

    // Style children as they arrive, then stop traversing a preview on every
    // animation frame once its asynchronous JSON load is complete.
    if (!mapObjectPreview.userData.mapPreviewStyleComplete) {
        mapObjectPreview.traverse(child => {
            if (!child.isMesh || child.userData.mapPreviewStyled) return;
            const materials = Array.isArray(child.material)
                ? child.material
                : [child.material];
            materials.forEach(material => {
                material.color.setHex(0x00ffff);
                if (material.map && material.map.userData.threeDPLMaterials) {
                    material.map.userData.threeDPLMaterials.delete(material);
                }
                material.map = null;
                material.wireframe = true;
                material.transparent = true;
                material.opacity = 0.45;
                material.depthTest = false;
                material.depthWrite = false;
                material.needsUpdate = true;
            });
            child.renderOrder = 999;
            child.userData.mapPreviewStyled = true;
        });
        if (mapObjectPreview.userData.loaded &&
            mapObjectPreview.userData.mapPreviewBatch) {
            mapObjectPreview.userData.mapPreviewStyleComplete = true;
        }
    }
}

function selectMapEditorObject(filename) {
    selectedMapObject = filename;
    selectedMapObjectLabel.textContent = `Selected: ${filename}`;
    selectedMapObjectLabel.style.color = '#cccccc';
    selectMapObjectButton.textContent = 'Change JSON Object';
    mapObjectGrid.querySelectorAll('.map-object-option').forEach(option => {
        option.classList.toggle('selected', option.dataset.filename === filename);
    });
    closeMapObjectPicker();
    createMapObjectPreview();
}

function objectLibraryAssetUrl(relativePath) {
    const path = 'Objects/' + relativePath
        .split('/')
        .map(segment => encodeURIComponent(segment))
        .join('/');
    return `${path}?v=${encodeURIComponent(mapObjectLibraryRevision)}`;
}

function normalizeObjectPreviewReference(value, fallback) {
    let reference = String(value || fallback).trim().replace(/\\/g, '/');
    while (reference.startsWith('./')) reference = reference.substring(2);
    if (/^\/?Objects\//i.test(reference)) {
        reference = reference.replace(/^\/?Objects\//i, '');
    }

    const pathOnly = reference.split(/[?#]/, 1)[0];
    if (!pathOnly || pathOnly.startsWith('/') ||
        pathOnly.split('/').includes('..') ||
        !/\.(?:png|jpe?g|webp|gif)$/i.test(pathOnly)) {
        return fallback;
    }
    return reference;
}

function normalizeMapObjectLibraryEntry(record) {
    const rawFile = typeof record === 'string' ? record : record?.file;
    const filename = normalizeObjectJSONReference(rawFile || '');
    if (isDirectAssetUrl(filename) || !isJSONObjectReference(filename) ||
        filename.toLowerCase() === 'objects-manifest.json') {
        return null;
    }

    const fallbackPreview = previewPathForMapObject(filename);
    return {
        file: filename,
        label: String(record?.label || filename),
        preview: normalizeObjectPreviewReference(
            record?.preview,
            fallbackPreview
        )
    };
}

async function loadMapObjectLibrary() {
    try {
        // The changing query also bypasses cached 404 responses after the
        // manifest is uploaded to a static host for the first time.
        const manifestUrl =
            `Objects/objects-manifest.json?refresh=${Date.now()}`;
        const response = await fetch(manifestUrl, {
            cache: 'no-store'
        });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.json();
        const records = Array.isArray(data) ? data : data.objects;
        if (!Array.isArray(records)) {
            throw new Error('Manifest has no objects array.');
        }

        const seen = new Set();
        const manifestObjects = records
            .map(normalizeMapObjectLibraryEntry)
            .filter(entry => {
                if (!entry || seen.has(entry.file)) return false;
                seen.add(entry.file);
                return true;
            })
            .sort((a, b) => a.label.localeCompare(b.label));

        if (manifestObjects.length === 0) {
            throw new Error('Manifest contains no JSON objects.');
        }

        availableMapObjects = manifestObjects;
        mapObjectLibraryRevision = String(data.revision || Date.now());
        mapObjectLibraryMessage =
            `${availableMapObjects.length} JSON objects loaded from the server`;
        mapObjectLibraryMessageColor = '#aaaaaa';
        return true;
    } catch (error) {
        mapObjectLibraryMessage =
            `Could not refresh object list; showing ${availableMapObjects.length} built-in objects.`;
        mapObjectLibraryMessageColor = '#ffcc66';
        console.warn('Object manifest load error:', error);
        return false;
    }
}

function renderMapObjectChoices(filterText = '') {
    const renderId = ++mapObjectPickerRenderId;
    const filter = filterText.trim().toLowerCase();
    const fragment = document.createDocumentFragment();
    mapObjectGrid.innerHTML = '';

    const matchingObjects = availableMapObjects.filter(entry =>
        entry.file.toLowerCase().includes(filter) ||
        entry.label.toLowerCase().includes(filter)
    );

    let loadedPreviews = 0;
    let failedPreviews = 0;
    const updateLibraryStatus = () => {
        if (renderId !== mapObjectPickerRenderId) return;
        const matchMessage = filter
            ? ` • ${matchingObjects.length} search matches`
            : '';
        const previewMessage = matchingObjects.length > 0
            ? ` • previews ${loadedPreviews}/${matchingObjects.length}`
            : '';
        const failureMessage = failedPreviews > 0
            ? ` • ${failedPreviews} preview files missing`
            : '';
        mapObjectLibraryStatus.textContent =
            mapObjectLibraryMessage + matchMessage + previewMessage + failureMessage;
        mapObjectLibraryStatus.style.color = failedPreviews > 0
            ? '#ff7777'
            : mapObjectLibraryMessageColor;
    };
    updateLibraryStatus();

    matchingObjects.forEach(entry => {
        const option = document.createElement('button');
        option.type = 'button';
        option.className = 'map-object-option';
        option.dataset.filename = entry.file;
        option.title = entry.file;
        option.classList.toggle('selected', entry.file === selectedMapObject);

        const previewFrame = document.createElement('span');
        previewFrame.className = 'map-object-preview';
        const preview = document.createElement('img');
        preview.alt = `${entry.label} preview`;
        preview.loading = 'lazy';
        preview.decoding = 'async';

        const placeholder = document.createElement('span');
        placeholder.className = 'map-object-preview-placeholder';
        placeholder.textContent = 'Preview file missing';
        placeholder.title = `Missing Objects/${entry.preview}`;
        placeholder.style.display = 'none';
        preview.onload = () => {
            if (renderId !== mapObjectPickerRenderId) return;
            loadedPreviews++;
            updateLibraryStatus();
        };
        preview.onerror = () => {
            if (renderId !== mapObjectPickerRenderId) return;
            failedPreviews++;
            preview.style.display = 'none';
            placeholder.style.display = 'flex';
            updateLibraryStatus();
        };
        preview.src = objectLibraryAssetUrl(entry.preview);
        previewFrame.append(preview, placeholder);

        const label = document.createElement('span');
        label.className = 'map-object-option-label';
        label.textContent = entry.label;
        option.append(previewFrame, label);
        option.onclick = () => selectMapEditorObject(entry.file);
        fragment.appendChild(option);
    });

    if (matchingObjects.length === 0) {
        const noMatches = document.createElement('div');
        noMatches.className = 'map-object-no-results';
        noMatches.textContent = 'No matching JSON objects.';
        fragment.appendChild(noMatches);
    }

    mapObjectGrid.appendChild(fragment);
}

function closeMapObjectPicker() {
    mapObjectPicker.style.display = 'none';
}

function isDirectAssetUrl(value) {
    return /^(?:https?:|data:|blob:)/i.test(value);
}

function normalizeObjectJSONReference(value) {
    let reference = String(value || '').trim().replace(/\\/g, '/');
    if (isDirectAssetUrl(reference)) return reference;

    while (reference.startsWith('./')) {
        reference = reference.substring(2);
    }
    if (reference.startsWith('/Objects/')) {
        reference = reference.substring('/Objects/'.length);
    } else if (/^Objects\//i.test(reference)) {
        reference = reference.substring('Objects/'.length);
    }

    const pathOnly = reference.split(/[?#]/, 1)[0];
    if (!pathOnly ||
        pathOnly.startsWith('/') ||
        /^[a-z]:\//i.test(pathOnly) ||
        pathOnly.split('/').includes('..')) {
        throw new Error(
            'Map object files must be JSON files inside the Objects/ directory.'
        );
    }
    return reference;
}

function objectJSONUrl(value) {
    const reference = normalizeObjectJSONReference(value);
    return isDirectAssetUrl(reference) ? reference : `Objects/${reference}`;
}

function isJSONObjectReference(value) {
    if (!value) return false;
    const withoutQuery = String(value).split(/[?#]/, 1)[0];
    return /\.json$/i.test(withoutQuery);
}

function finiteMapNumber(value, fallback) {
    const number = Number(value);
    return Number.isFinite(number) ? number : fallback;
}

function normalizeMapEntry(entry, index) {
    if (!entry || typeof entry !== 'object' || Array.isArray(entry)) {
        throw new Error(`Map entry ${index + 1} is not an object.`);
    }

    const primitive = String(entry.primitive || '').toLowerCase();
    const isInlineCube = primitive === 'cube';
    const file = isInlineCube
        ? ''
        : normalizeObjectJSONReference(
            entry.file || entry.filename || entry.objectFile || ''
        );

    if (!isInlineCube && !isJSONObjectReference(file)) {
        throw new Error(
            `Map entry ${index + 1} must contain a JSON object file or cube primitive.`
        );
    }

    const scale = Math.max(0.01, finiteMapNumber(entry.scale, 1));
    const normalized = {
        name: String(entry.name || '').trim(),
        x: finiteMapNumber(entry.x, 0),
        y: finiteMapNumber(entry.y, 0),
        z: finiteMapNumber(entry.z, 0),
        rotationY: finiteMapNumber(entry.rotationY, 0),
        scale
    };

    if (isInlineCube) {
        normalized.primitive = 'cube';
        normalized.color = Array.isArray(entry.color)
            ? entry.color.slice(0, 3).map(component =>
                Math.max(0, Math.min(255, finiteMapNumber(component, 136))))
            : (entry.color !== undefined ? entry.color : [136, 136, 136]);
        normalized.alpha = Math.max(
            0,
            Math.min(1, finiteMapNumber(entry.alpha, 1))
        );
        normalized.voxelName = String(entry.voxelName || '').trim();
    } else {
        normalized.file = file;
    }

    return normalized;
}

function normalizeMapDocument(data) {
    const entries = Array.isArray(data)
        ? data
        : (data && Array.isArray(data.objects) ? data.objects : null);

    if (!entries) throw new Error('Map JSON has no objects array.');
    return entries.map((entry, index) => normalizeMapEntry(entry, index));
}

function instantiateMapEntry(entry, fallbackName) {
    const instanceName = entry.name || fallbackName;
    const object = entry.primitive === 'cube'
        ? createInlineMapCube(entry, instanceName)
        : window.Obj(entry.file, instanceName, 0, 0, 0);

    object.position.set(entry.x, entry.y, entry.z);
    object.rotation.set(
        0,
        THREE.MathUtils.degToRad(-entry.rotationY),
        0,
        'YXZ'
    );
    object.scale.set(entry.scale, entry.scale, entry.scale);
    object.userData.mapEntryDefinition = entry;
    object.userData.sourceFile = entry.file || '';
    object.userData.mapPrimitive = entry.primitive || '';
    return object;
}

function requiredMapObjectFilename(object) {
    const sourceFile = object.userData.mapEntryDefinition?.file ||
        object.userData.sourceFile || object.name;
    return isDirectAssetUrl(sourceFile)
        ? sourceFile
        : `Objects/${sourceFile}`;
}

function requiredMapObjectError(object) {
    const filename = requiredMapObjectFilename(object);
    const reason = object.userData.loadError || 'Unknown loading error';
    return `Required map object "${filename}" could not be loaded: ${reason}`;
}

function placeMapEditorObject(entry) {
    const normalizedEntry = normalizeMapEntry(entry, 0);
    const object = instantiateMapEntry(
        normalizedEntry,
        `map_object_${generateId()}`
    );
    object.userData.mapEditorObject = true;
    object.userData.ready.then(() =>
        scheduleMapEditorObjectOptimization(object));
    updateMapObjectCount();
    return object;
}

function exportMapEditorJSON() {
    const objects = cubes
        .filter(object => object.userData.mapEditorObject)
        .map(object => {
            const entry = {
                name: object.name,
                x: object.position.x,
                y: object.position.y,
                z: object.position.z,
                rotationY: -THREE.MathUtils.radToDeg(object.rotation.y),
                scale: object.scale.x
            };

            const definition = object.userData.mapEntryDefinition || {};
            if (definition.primitive === 'cube') {
                entry.primitive = 'cube';
                entry.color = definition.color;
                if (definition.alpha !== 1) {
                    entry.alpha = definition.alpha;
                }
                if (definition.voxelName) {
                    entry.voxelName = definition.voxelName;
                }
            } else {
                entry.file = definition.file || object.userData.sourceFile;
            }

            return entry;
        });
    const mapData = { format: '3dpl-map-v1', objects };
    const blob = new Blob([JSON.stringify(mapData, null, 2)], { type: 'application/json' });
    const url = URL.createObjectURL(blob);
    const download = document.createElement('a');
    download.href = url;
    download.download = 'my_map.json';
    download.click();
    URL.revokeObjectURL(url);
}

selectMapObjectButton.onclick = async () => {
    const opening = mapObjectPicker.style.display !== 'flex';
    if (!opening) {
        closeMapObjectPicker();
        return;
    }

    mapObjectPicker.style.display = 'flex';
    mapObjectSearch.value = '';
    mapObjectLibraryMessage =
        `Refreshing server list; showing ${availableMapObjects.length} objects...`;
    mapObjectLibraryMessageColor = '#aaaaaa';
    renderMapObjectChoices('');
    await loadMapObjectLibrary();

    if (mapObjectPicker.style.display === 'flex') {
        renderMapObjectChoices('');
        mapObjectSearch.focus();
    }
};
closeMapObjectPickerButton.onclick = closeMapObjectPicker;
mapObjectSearch.oninput = () => renderMapObjectChoices(mapObjectSearch.value);
rotateMapObjectButton.onclick = () => {
    const currentRotation = Number(mapObjectRotationInput.value) || 0;
    mapObjectRotationInput.value = ((currentRotation + 90) % 360 + 360) % 360;
    syncMapObjectPreview();
};

function centerMapPointerOnView() {
    editorPointer.position.set(
        Math.round(camera.position.x),
        Number(mapObjectYInput.value) || 0,
        Math.round(camera.position.z)
    );
    syncMapObjectPreview();
}

centerMapPointerButton.onclick = centerMapPointerOnView;

function frameMapEditorObjects() {
    const objects = cubes.filter(object => object.userData.mapEditorObject);
    if (objects.length === 0) return;

    scene.updateMatrixWorld(true);
    const bounds = new THREE.Box3();
    objects.forEach(object => bounds.expandByObject(object));
    if (bounds.isEmpty()) return;

    const center = bounds.getCenter(new THREE.Vector3());
    const size = bounds.getSize(new THREE.Vector3());
    const verticalFov = THREE.MathUtils.degToRad(camera.fov);
    const requiredHeightForZ = size.z / (2 * Math.tan(verticalFov / 2));
    const horizontalFov = 2 * Math.atan(
        Math.tan(verticalFov / 2) * camera.aspect
    );
    const requiredHeightForX = size.x / (2 * Math.tan(horizontalFov / 2));
    const height = Math.max(10, requiredHeightForX, requiredHeightForZ) * 1.25;

    camera.position.set(center.x, bounds.max.y + height, center.z);
    camera.rotation.set(-Math.PI / 2, 0, 0, 'YXZ');
    const mapDepth = camera.position.y - bounds.min.y;
    camera.far = Math.max(1000, mapDepth * 2, size.length() * 2);
    camera.updateProjectionMatrix();
    editorPointer.position.x = Math.round(center.x);
    editorPointer.position.z = Math.round(center.z);
}

document.getElementById('btn-export-map').onclick = exportMapEditorJSON;
document.getElementById('btn-load-map').onclick = () => document.getElementById('file-input-map').click();
document.getElementById('file-input-map').onchange = event => {
    const file = event.target.files[0];
    if (!file) return;
    const loadToken = ++mapEditorLoadToken;
    const reader = new FileReader();
    reader.onload = loadEvent => {
        let stagedObjects = [];
        try {
            const mapData = JSON.parse(loadEvent.target.result);
            // Validate every entry before replacing the map currently in the editor.
            const entries = normalizeMapDocument(mapData);
            discardPendingMapEditorObjects();
            entries.forEach((entry, index) => {
                const object = instantiateMapEntry(
                    entry,
                    `map_object_${index + 1}`
                );
                stagedObjects.push(object);
                object.removeFromParent();
                const cubeIndex = cubes.indexOf(object);
                if (cubeIndex !== -1) cubes.splice(cubeIndex, 1);
            });
            pendingMapEditorObjects = stagedObjects;
            selectedMapObjectLabel.textContent = `Loading map: ${file.name}`;
            selectedMapObjectLabel.style.color = '#cccccc';

            Promise.all(stagedObjects.map(object => object.userData.ready))
                .then(() => {
                    if (!mapEditorMode || loadToken !== mapEditorLoadToken) {
                        discardPendingMapEditorObjects(stagedObjects);
                        return;
                    }
                    const failedObject = stagedObjects.find(
                        object => object.userData.loadError
                    );
                    if (failedObject) {
                        throw new Error(requiredMapObjectError(failedObject));
                    }

                    pendingMapEditorObjects = [];
                    removeMapObjectPreview();
                    window.cs();
                    stagedObjects.forEach(object => {
                        scene.add(object);
                        cubes.push(object);
                        object.userData.mapEditorObject = true;
                        scheduleMapEditorObjectOptimization(object);
                    });
                    createMapObjectPreview();
                    updateMapObjectCount();
                    frameMapEditorObjects();
                    selectedMapObjectLabel.textContent = `Loaded map: ${file.name}`;
                    selectedMapObjectLabel.style.color = '#cccccc';
                })
                .catch(error => {
                    discardPendingMapEditorObjects(stagedObjects);
                    if (loadToken !== mapEditorLoadToken) return;
                    setDebugError(`Map load error: ${error.message}`);
                    selectedMapObjectLabel.textContent =
                        `Map load failed: ${error.message}`;
                    selectedMapObjectLabel.style.color = '#ff7777';
                    console.error('Map load error:', error);
                });
        } catch (error) {
            discardPendingMapEditorObjects(stagedObjects);
            if (loadToken !== mapEditorLoadToken) return;
            setDebugError(`Map load error: ${error.message}`);
            selectedMapObjectLabel.textContent =
                `Map load failed: ${error.message}`;
            selectedMapObjectLabel.style.color = '#ff7777';
            console.error('Map load error:', error);
        }
        event.target.value = '';
    };
    reader.onerror = () => {
        if (loadToken !== mapEditorLoadToken) return;
        const message = reader.error ? reader.error.message : 'Could not read the file.';
        setDebugError(`Map load error: ${message}`);
        selectedMapObjectLabel.textContent = `Map load failed: ${file.name}`;
        selectedMapObjectLabel.style.color = '#ff7777';
        event.target.value = '';
    };
    reader.readAsText(file);
};

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
    Space: 'Space', LeftControl: 'ControlLeft', C: 'KeyC', E: 'KeyE', Q: 'KeyQ', A: 'KeyA', Z: 'KeyZ', X: 'KeyX', W: 'KeyW', S: 'KeyS', D: 'KeyD', R: 'KeyR', F: 'KeyF'
};

const gameplayKeyCodes = new Set(Object.values(window.KeyCode));

window.addEventListener('keydown', e => {
    const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
    const isTyping = activeTag === 'input' || activeTag === 'textarea';

    if (e.code === 'Escape' && mapObjectPicker.style.display === 'flex') {
        e.preventDefault();
        closeMapObjectPicker();
        return;
    }

    if ((e.code === 'Space' && !isTyping) || (isExecuting && gameplayKeyCodes.has(e.code))) {
        e.preventDefault();
    }
    if (e.code === 'KeyQ' && document.pointerLockElement) document.exitPointerLock();
    if (isTyping) return;
    
    window.Input._keys[e.code] = true;
}, true);

window.addEventListener('keyup', e => {
    window.Input._keys[e.code] = false;
}, true);

renderer.domElement.addEventListener('mousedown', () => {
    if (document.activeElement) document.activeElement.blur();
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

// Injects Unity-like properties into Three.js objects to support legacy math
function injectUnityCompatibility(mesh) {
    mesh.transform = mesh; // Fixes the vars.car.transform is undefined error
    Object.defineProperty(mesh, 'eulerAngles', {
        get: function() {
            return {
                x: -this.rotation.x * (180 / Math.PI),
                y: -this.rotation.y * (180 / Math.PI),
                z: -this.rotation.z * (180 / Math.PI)
            };
        }
    });
}

// Obj() collider meshes are nested below groups, but looking them up by name
// must not require traversing a 60,000-voxel map every update substep.
const collisionObjectNameIndex = new Map();

function indexCollisionObject(object) {
    if (!object || !object.name) return;
    let matches = collisionObjectNameIndex.get(object.name);
    if (!matches) {
        matches = new Set();
        collisionObjectNameIndex.set(object.name, matches);
    }
    matches.add(object);
}

function unindexCollisionObject(object) {
    if (!object || !object.name) return;
    const matches = collisionObjectNameIndex.get(object.name);
    if (!matches) return;
    matches.delete(object);
    if (matches.size === 0) collisionObjectNameIndex.delete(object.name);
}

function belongsToActiveCube(object) {
    let root = object;
    while (root.parent && root.parent !== scene) root = root.parent;
    return root.parent === scene && cubes.includes(root);
}

function traverseObjectTreeRaw(root, callback) {
    callback(root);
    for (const child of root.children) {
        // Render batches are an internal mirror of original map voxels. They
        // never participate in public name lookup or collision discovery.
        if (child.userData.mapRenderBatch) continue;
        traverseObjectTreeRaw(child, callback);
    }
}

function forEachObjectMaterial(object, callback) {
    object.traverse(child => {
        if (!child.isMesh || !child.material) return;
        const materials = Array.isArray(child.material) ? child.material : [child.material];
        materials.forEach(material => callback(material, child));
    });
}

function disposeObjectMaterials(object) {
    forEachObjectMaterial(object, material => {
        if (material.map && material.map.userData.threeDPLMaterials) {
            material.map.userData.threeDPLMaterials.delete(material);
        }
        material.dispose();
    });
}

function cancelObjectLoads(object) {
    object.traverse(child => {
        child.userData.cancelled = true;
        unindexCollisionObject(child);
    });
}

window.qb = function(name, x, y, z) {
    const material = new THREE.MeshStandardMaterial({ color: 0x888888, transparent: true, opacity: 1.0 });
    const mesh = new THREE.Mesh(baseGeometry, material);
    mesh.position.set(x, y, z);
    mesh.name = name;
    injectUnityCompatibility(mesh);
    scene.add(mesh);
    cubes.push(mesh);
    return mesh;
};

window.cl = function(name, colorHex) {
    cubes.forEach(c => { 
        if (c.name === name) {
            forEachObjectMaterial(c, material => material.color.setHex(colorHex));
        }
    });
};

window.alpha = function(name, alphaVal) {
    cubes.forEach(c => { 
        if (c.name === name) {
            forEachObjectMaterial(c, material => {
                const needsTransparency = materialNeedsTransparency(
                    material,
                    alphaVal
                );
                if (material.transparent !== needsTransparency) {
                    material.transparent = needsTransparency;
                    material.needsUpdate = true;
                }
                material.opacity = alphaVal;
            });
        }
    });
};

window.tx = function(name, textureName, source, wrap) {
    wrap = wrap || 6;
    loadSharedTexture(textureName);
    
    cubes.forEach(c => { 
        if (c.name === name) {
            forEachObjectMaterial(c, (material, mesh) => {
                assignSharedTexture(material, textureName);
                material.needsUpdate = true;
                mesh.userData.textureName = textureName;
                mesh.userData.wrap = wrap;

                if (wrap === 1 || wrap === "1") {
                    mesh.geometry = getWrappedBaseGeometry();
                } else {
                    if (mesh.geometry !== baseGeometry) mesh.geometry = baseGeometry;
                }
            });
        }
    });
};

// Transforms 
window.mv = function(name, x, y, z) {
    if (name === "camera") {
        camera.translateX(x); 
        camera.translateY(y); 
        camera.translateZ(-z); 
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
    const radX = -x * (Math.PI / 180);
    const radY = -y * (Math.PI / 180);
    const radZ = -z * (Math.PI / 180);
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
    const radX = -x * (Math.PI / 180);
    const radY = -y * (Math.PI / 180);
    const radZ = -z * (Math.PI / 180);
    if (name === "camera") { camera.rotation.set(radX, radY, radZ, 'YXZ'); return; }
    cubes.forEach(c => {
        if (c.name === name) c.rotation.set(radX, radY, radZ, 'YXZ');
    });
};

window.sr = window.an;

window.sc = function(name, x, y, z) {
    cubes.forEach(c => { if (c.name === name) c.scale.set(x, y, z); });
};

// State & Group Collision 
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

function findCollisionObjects(target) {
    if (target === "camera") return [camera];
    if (target && target.isObject3D) return [target];
    if (typeof target !== 'string') return [];

    if (vars[target] && vars[target].isObject3D) return [vars[target]];

    const topLevelMatches = [...new Set(
        cubes.filter(object => object.name === target)
    )];
    if (topLevelMatches.length > 0) return topLevelMatches;

    const indexedMatches = collisionObjectNameIndex.get(target);
    if (indexedMatches) {
        const activeMatches = [];
        indexedMatches.forEach(object => {
            // A user may directly rename an Object3D. Drop that stale key and
            // let the traversal fallback discover its new name when requested.
            if (object.name !== target) {
                indexedMatches.delete(object);
            } else if (belongsToActiveCube(object)) {
                activeMatches.push(object);
            } else {
                indexedMatches.delete(object);
            }
        });
        if (indexedMatches.size === 0) collisionObjectNameIndex.delete(target);
        if (activeMatches.length > 0) return [...new Set(activeMatches)];
    }

    // Collider blocks loaded inside an Obj() group are not top-level cubes.
    // Keep a fallback for objects renamed or added directly by user code.
    const nestedMatches = new Set();
    cubes.forEach(root => {
        traverseObjectTreeRaw(root, child => {
            if (child !== root && child.name === target) {
                nestedMatches.add(child);
                indexCollisionObject(child);
            }
        });
    });
    return [...nestedMatches];
}

function collisionVoxelDataFromObjectData(data) {
    if (Array.isArray(data)) return data;
    if (data && Array.isArray(data.voxels)) return data.voxels;
    if (data && Array.isArray(data.objects)) return data.objects;
    return null;
}

function buildVoxelCollisionNode(entries) {
    const bounds = new THREE.Box3().makeEmpty();
    entries.forEach(entry => bounds.union(entry.box));

    if (entries.length <= 12) {
        return { bounds, boxes: entries.map(entry => entry.box) };
    }

    const size = bounds.getSize(new THREE.Vector3());
    const axis = size.x >= size.y && size.x >= size.z
        ? 0
        : (size.y >= size.z ? 1 : 2);
    entries.sort((left, right) => left.center[axis] - right.center[axis]);
    const middle = Math.floor(entries.length / 2);
    return {
        bounds,
        left: buildVoxelCollisionNode(entries.slice(0, middle)),
        right: buildVoxelCollisionNode(entries.slice(middle))
    };
}

function buildObjectCollisionData(voxelData) {
    const entries = [];
    voxelData.forEach(voxel => {
        const voxelName = voxel.cubename || voxel.name || 'cube';
        if (voxelName === 'AxisPoint') return;

        const x = finiteMapNumber(voxel.x, 0);
        const y = finiteMapNumber(voxel.y, 0);
        const z = finiteMapNumber(voxel.z, 0);
        entries.push({
            center: [x, y, z],
            box: new THREE.Box3(
                new THREE.Vector3(x - 0.5, y - 0.5, z - 0.5),
                new THREE.Vector3(x + 0.5, y + 0.5, z + 0.5)
            )
        });
    });

    if (entries.length === 0) return null;
    const tree = buildVoxelCollisionNode(entries);
    return { localBounds: tree.bounds, tree };
}

function ensureMapItemCollisionData(object) {
    if (object.userData.collisionCacheDisabled) return null;
    if (!object.userData.mapEntry || !object.userData.loaded) return null;
    const existingCollisionData = objectVoxelCollisionData.get(object);
    if (existingCollisionData) {
        return existingCollisionData;
    }

    const sourceFile = object.userData.sourceFile;
    if (!sourceFile) return null;
    const url = objectJSONUrl(sourceFile);
    let collisionData = objectCollisionDataCache.get(url);

    if (!collisionData) {
        const voxelData = collisionVoxelDataFromObjectData(
            loadedObjectCache[url]
        );
        if (!voxelData) return null;
        collisionData = buildObjectCollisionData(voxelData);
        if (!collisionData) return null;
        objectCollisionDataCache.set(url, collisionData);
    }

    objectVoxelCollisionData.set(object, collisionData);
    objectCollisionLocalBoxes.set(object, collisionData.localBounds);
    return collisionData;
}

function collisionTreeTouchesWorldBox(node, matrixWorld, colliderBox, scratchBox) {
    scratchBox.copy(node.bounds).applyMatrix4(matrixWorld);
    if (!scratchBox.intersectsBox(colliderBox)) return false;

    if (node.boxes) {
        for (const localBox of node.boxes) {
            scratchBox.copy(localBox).applyMatrix4(matrixWorld);
            if (scratchBox.intersectsBox(colliderBox)) return true;
        }
        return false;
    }

    return collisionTreeTouchesWorldBox(
        node.left, matrixWorld, colliderBox, scratchBox
    ) || collisionTreeTouchesWorldBox(
        node.right, matrixWorld, colliderBox, scratchBox
    );
}

function collisionBoxFor(object) {
    if (object === camera) {
        return new THREE.Box3().setFromCenterAndSize(
            camera.position,
            new THREE.Vector3(1, 1, 1)
        );
    }

    // A map item can contain thousands of voxel meshes. Build its bounds once
    // in local space, then transform that small cached box when the map moves.
    // The final voxel check remains exact, so a broad-phase false positive is
    // harmless while repeated full-tree Box3 scans are avoided.
    if (object.userData.mapEntry && object.userData.loaded) {
        if (object.userData.collisionCacheDisabled) {
            return new THREE.Box3().setFromObject(object);
        }
        ensureMapItemCollisionData(object);
        let collisionLocalBox = objectCollisionLocalBoxes.get(object);
        if (!collisionLocalBox) {
            object.updateWorldMatrix(true, true);
            const inverseRootMatrix = new THREE.Matrix4()
                .copy(object.matrixWorld)
                .invert();
            const localBounds = new THREE.Box3().makeEmpty();

            object.traverse(child => {
                if (!child.isMesh || !child.geometry) return;
                if (!child.geometry.boundingBox) {
                    child.geometry.computeBoundingBox();
                }
                if (!child.geometry.boundingBox) return;

                const childToRoot = new THREE.Matrix4().multiplyMatrices(
                    inverseRootMatrix,
                    child.matrixWorld
                );
                localBounds.union(
                    child.geometry.boundingBox.clone().applyMatrix4(childToRoot)
                );
            });

            if (!localBounds.isEmpty()) {
                objectCollisionLocalBoxes.set(object, localBounds);
                collisionLocalBox = localBounds;
            }
        }

        if (collisionLocalBox) {
            object.updateWorldMatrix(true, false);
            return collisionLocalBox.clone()
                .applyMatrix4(object.matrixWorld);
        }
    }

    return new THREE.Box3().setFromObject(object);
}

window.cd = function(nameA, nameB) {
    const objsA = findCollisionObjects(nameA);
    const objsB = findCollisionObjects(nameB);
    
    if (objsA.length === 0 || objsB.length === 0) return false;

    for (let a of objsA) {
        const boxA = collisionBoxFor(a);
        for (let b of objsB) {
            const boxB = collisionBoxFor(b);
            if (boxA.intersectsBox(boxB)) return true;
        }
    }
    return false;
};

function collectColliderMeshes(colliderObject, colliderName) {
    const roots = findCollisionObjects(colliderObject);
    const meshes = new Set();

    roots.forEach(root => {
        if (root.isMesh) {
            if (!colliderName || root.name === colliderName) meshes.add(root);
            return;
        }
        root.traverse(child => {
            if (child.isMesh && (!colliderName || child.name === colliderName)) {
                meshes.add(child);
            }
        });
    });

    return [...meshes];
}

function collectNamedMeshesWithin(roots, meshName) {
    const meshes = new Set();
    roots.forEach(root => {
        if (root.isMesh && root.name === meshName) meshes.add(root);
        root.traverse(child => {
            if (child.isMesh && child.name === meshName) meshes.add(child);
        });
    });
    return [...meshes];
}

function colliderMeshTouchesLand(land, colliderBox) {
    const collisionData = objectVoxelCollisionData.get(land) ||
        ensureMapItemCollisionData(land);
    if (collisionData) {
        land.updateWorldMatrix(true, false);
        return collisionTreeTouchesWorldBox(
            collisionData.tree,
            land.matrixWorld,
            colliderBox,
            new THREE.Box3()
        );
    }

    // This fallback keeps the exact Box3 behavior used by legacy XML maps and
    // arbitrary user-created land objects, while avoiding one Box3 allocation
    // per voxel.
    land.updateWorldMatrix(true, true);
    const voxelBox = new THREE.Box3();
    let touching = false;

    land.traverse(voxel => {
        if (touching || !voxel.isMesh || !voxel.geometry) return;
        if (!voxel.geometry.boundingBox) voxel.geometry.computeBoundingBox();
        if (!voxel.geometry.boundingBox) return;
        voxelBox.copy(voxel.geometry.boundingBox).applyMatrix4(voxel.matrixWorld);
        if (colliderBox.intersectsBox(voxelBox)) touching = true;
    });

    return touching;
}

function prepareMapCollisionItems(maps) {
    const preparedItems = [];
    maps.forEach(map => {
        const mapItems = map.userData.is3DPLMap ? map.children : [map];
        mapItems.forEach(mapItem => {
            preparedItems.push({
                mapItem,
                box: collisionBoxFor(mapItem)
            });
        });
    });
    return preparedItems;
}

function resolvedCollidersTouchMaps(
    maps,
    colliderMeshes,
    preparedMapItems
) {
    const colliderBoxes = colliderMeshes.map(colliderMesh => {
        colliderMesh.updateWorldMatrix(true, false);
        return new THREE.Box3().setFromObject(colliderMesh);
    });
    const mapItems = preparedMapItems || prepareMapCollisionItems(maps);

    for (const preparedItem of mapItems) {
        for (let index = 0; index < colliderMeshes.length; index++) {
            const colliderBox = colliderBoxes[index];
            if (!preparedItem.box.intersectsBox(colliderBox)) continue;
            if (colliderMeshTouchesLand(
                preparedItem.mapItem,
                colliderBox
            )) return true;
        }
    }

    return false;
}

/**
 * Efficiently checks a named collider/object against a LoadMap() map.
 * First it uses cd() against each placed map item. Only overlapping items
 * receive the more expensive voxel-by-voxel is_touching_voxel() check.
 *
 * @param {Object|string} mapObject - LoadMap() result, variable key, or name.
 * @param {Object|string} colliderObject - Collider group, object, or name.
 * @param {string} [colliderName] - Optional named mesh inside colliderObject.
 * @returns {boolean} True when one of the collider meshes touches a map voxel.
 */
window.is_object_colliding_with_map = function(
    mapObject,
    colliderObject,
    colliderName
) {
    const maps = findCollisionObjects(mapObject);
    const colliderMeshes = collectColliderMeshes(colliderObject, colliderName);
    if (colliderMeshes.length === 0) return false;
    return maps.length > 0 && resolvedCollidersTouchMaps(maps, colliderMeshes);
};

/**
 * Moves an object in small steps and rolls back the first step that collides.
 * This prevents fast objects from jumping completely through a thin map voxel.
 * Coordinates follow mv(): positive z moves in the object's forward direction.
 *
 * @returns {boolean} True if the entire requested movement was completed.
 */
window.move_object_with_map_collision = function(
    mapObject,
    movingObject,
    colliderObject,
    x,
    y,
    z,
    maxStep
) {
    const maps = findCollisionObjects(mapObject);
    const movingObjects = findCollisionObjects(movingObject);
    // A collider name belongs to the object being moved whenever possible.
    // This prevents another active vehicle with the same probe name from
    // incorrectly blocking movement. Global lookup remains as a fallback for
    // legacy programs that keep collider objects separate.
    let colliderMeshes = typeof colliderObject === 'string'
        ? collectNamedMeshesWithin(movingObjects, colliderObject)
        : collectColliderMeshes(colliderObject);
    if (colliderMeshes.length === 0) {
        colliderMeshes = collectColliderMeshes(colliderObject);
    }

    if (maps.length === 0 ||
        movingObjects.length === 0 ||
        colliderMeshes.length === 0) {
        return false;
    }

    // Do not allow movement through an empty placeholder while LoadMap() or
    // the collider JSON is still downloading.
    if (maps.some(map => map.userData.is3DPLMap && !map.userData.loaded)) {
        return false;
    }
    const dx = Number(x) || 0;
    const dy = Number(y) || 0;
    const dz = Number(z) || 0;
    const distance = Math.sqrt(dx * dx + dy * dy + dz * dz);
    if (distance === 0) return true;

    const safeStep = Math.max(0.01, Number(maxStep) || 0.2);
    const steps = Math.max(1, Math.ceil(distance / safeStep));
    const stepX = dx / steps;
    const stepY = dy / steps;
    const stepZ = dz / steps;
    const preparedMapItems = prepareMapCollisionItems(maps);

    for (let step = 0; step < steps; step++) {
        movingObjects.forEach(object => {
            object.translateX(stepX);
            object.translateY(stepY);
            object.translateZ(-stepZ);
        });

        if (resolvedCollidersTouchMaps(
            maps,
            colliderMeshes,
            preparedMapItems
        )) {
            movingObjects.forEach(object => {
                object.translateX(-stepX);
                object.translateY(-stepY);
                object.translateZ(stepZ);
            });
            colliderMeshes.forEach(mesh => mesh.updateWorldMatrix(true, false));
            return false;
        }
    }

    return true;
};

window.cdcm = function(obj, colliderName) {
    const o = typeof obj === 'string' ? cubes.find(c => c.name === obj) : obj;
    return o ? window.cd(o.name, colliderName) : false;
};

// Deletion
window.dl = function(name) {
    for (let i = cubes.length - 1; i >= 0; i--) {
        if (cubes[i].name === name) {
            cancelObjectLoads(cubes[i]);
            cubes[i].removeFromParent();
            disposeObjectMaterials(cubes[i]);
            cubes.splice(i, 1);
        }
    }
};

window.cs = function() {
    cubes.forEach(c => { 
        cancelObjectLoads(c);
        c.removeFromParent(); 
        disposeObjectMaterials(c);
    });
    cubes = [];
    vars = {}; 
    
    Object.values(sounds).forEach(s => {
        if (s.isPlaying) s.stop();
    });
    sounds = {};
};

// Audio Engine
window.AttachSound = function(cubeName, fileName) {
    const sound = new THREE.Audio(listener);
    audioLoader.load('Sounds/' + fileName, (buffer) => {
        sound.setBuffer(buffer);
        sound.setVolume(1.0);
    }, undefined, () => setDebugError(`Audio load error: Sounds/${fileName}`));
    sounds[cubeName] = sound;
};

window.PlaySound = function(cubeName) {
    if (sounds[cubeName] && !sounds[cubeName].isPlaying) sounds[cubeName].play();
};

window.PlaySoundLoop = function(cubeName) {
    if (sounds[cubeName]) {
        sounds[cubeName].setLoop(true);
        if (!sounds[cubeName].isPlaying) sounds[cubeName].play();
    }
};

window.StopSound = function(cubeName) {
    if (sounds[cubeName] && sounds[cubeName].isPlaying) sounds[cubeName].stop();
};

window.SetPitch = function(cubeName, pitch) {
    if (sounds[cubeName]) sounds[cubeName].setPlaybackRate(pitch);
};

window.SetVolume = function(cubeName, vol) {
    if (sounds[cubeName]) sounds[cubeName].setVolume(vol);
};

// --- Object and XML Loading ---
function fetchObjectData(url) {
    if (pendingObjectDataPromises[url]) {
        return pendingObjectDataPromises[url];
    }

    const request = fetch(url)
        .then(response => {
            if (!response.ok) {
                throw new Error(`File not found (HTTP ${response.status})`);
            }
            return response.json();
        })
        .then(data => {
            loadedObjectCache[url] = data;
            return data;
        });

    pendingObjectDataPromises[url] = request;
    request.then(
        () => delete pendingObjectDataPromises[url],
        () => delete pendingObjectDataPromises[url]
    );
    return request;
}

window.Obj = function(filenameOrUrl, instanceName, x, y, z) {
    if (!filenameOrUrl) return null;
    const normalizedReference = normalizeObjectJSONReference(filenameOrUrl);
    const url = objectJSONUrl(normalizedReference);
    x = x || 0; y = y || 0; z = z || 0;
    instanceName = instanceName || ("obj_" + Math.random().toString(36).substr(2, 5));

    const axisGroup = new THREE.Group();
    axisGroup.position.set(x, y, z);
    axisGroup.name = instanceName;
    axisGroup.userData.loaded = false;
    axisGroup.userData.sourceFile = normalizedReference;
    injectUnityCompatibility(axisGroup);
    scene.add(axisGroup);
    cubes.push(axisGroup);

    const buildVoxels = (voxelData) => {
        voxelData.forEach(cords => {
            const voxelName = cords.cubename || cords.name || "cube";
            if (voxelName === "AxisPoint") return;

            const red = finiteMapNumber(cords.r, 255);
            const green = finiteMapNumber(cords.g, 255);
            const blue = finiteMapNumber(cords.b, 255);
            const opacity = cords.alpha !== undefined
                ? finiteMapNumber(cords.alpha, 1)
                : finiteMapNumber(cords.a, 1);
            const material = new THREE.MeshStandardMaterial({
                color: new THREE.Color(red / 255, green / 255, blue / 255),
                // Unknown textures remain transparent so their PNG alpha is
                // preserved. Untextured alpha-1 voxels can use the faster
                // opaque render path with identical output.
                transparent: opacity < 1 || Boolean(cords.TextureName),
                opacity
            });

            let geometry = baseGeometry;
            if (cords.TextureName) {
                assignSharedTexture(material, cords.TextureName);

                if (cords.WrapOnSides == 1) {
                    geometry = getWrappedBaseGeometry();
                }
            }

            const mesh = new THREE.Mesh(geometry, material);
            mesh.position.set(
                finiteMapNumber(cords.x, 0),
                finiteMapNumber(cords.y, 0),
                finiteMapNumber(cords.z, 0)
            );
            mesh.name = voxelName;
            mesh.userData.textureName = cords.TextureName || "";
            mesh.userData.wrap = cords.WrapOnSides || 6;
            axisGroup.add(mesh);
            if (/collider/i.test(voxelName)) indexCollisionObject(mesh);
        });
    };

    const finishObjectLoad = data => {
        if (axisGroup.userData.cancelled) return axisGroup;

        let voxelData = null;
        if (Array.isArray(data)) {
            voxelData = data;
        } else if (Array.isArray(data.voxels)) {
            voxelData = data.voxels;
        } else if (Array.isArray(data.objects)) {
            voxelData = data.objects;
        }

        if (!voxelData) throw new Error('Unknown voxel JSON format.');
        buildVoxels(voxelData);
        axisGroup.userData.loaded = true;
        return axisGroup;
    };

    if (loadedObjectCache[url]) {
        try {
            finishObjectLoad(loadedObjectCache[url]);
        } catch (error) {
            axisGroup.userData.loadError = error.message;
            setDebugError(`Failed to load JSON object: ${url}`);
            console.error(error);
        }
        axisGroup.userData.ready = Promise.resolve(axisGroup);
    } else {
        axisGroup.userData.ready = fetchObjectData(url)
            .then(data => finishObjectLoad(data))
            .catch(err => {
                axisGroup.userData.loadError = err.message;
                setDebugError(`Failed to load JSON object: ${url}`);
                console.error(err);
                return axisGroup;
            });
    }
    return axisGroup;
};

window.XMLObj = function(filenameOrUrl, instanceName, x, y, z) {
    if (!filenameOrUrl) {
        setDebugError("XMLObj Error: Missing filename argument.");
        return null;
    }
    x = x || 0; y = y || 0; z = z || 0;
    instanceName = instanceName || ("xml_obj_" + Math.random().toString(36).substr(2, 5));

    const axisGroup = new THREE.Group();
    axisGroup.position.set(x, y, z);
    axisGroup.name = instanceName;
    axisGroup.userData.loaded = false;
    injectUnityCompatibility(axisGroup);
    scene.add(axisGroup);
    cubes.push(axisGroup);

    const url = filenameOrUrl.startsWith('http') ? filenameOrUrl : 'Objects/' + filenameOrUrl;
    axisGroup.userData.ready = fetch(url)
        .then(res => { if (!res.ok) throw new Error(`HTTP ${res.status}`); return res.text(); })
        .then(str => {
            if (axisGroup.userData.cancelled) return axisGroup;
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(str, "text/xml");
            
            if (xmlDoc.getElementsByTagName("parsererror").length > 0) {
                setDebugError(`XML Syntax Error in ${url}`);
                return axisGroup;
            }

            let nodes = xmlDoc.getElementsByTagName("Coordinates");
            if (nodes.length === 0) nodes = xmlDoc.getElementsByTagName("Object");
            const collisionVoxels = [];

            for (let i = 0; i < nodes.length; i++) {
                const n = nodes[i];
                const cubename = n.getAttribute("cubename") || "cube";
                if (cubename === "AxisPoint" || cubename.startsWith("collider")) continue;

                const bx = parseFloat(n.getAttribute("x") || 0);
                const by = parseFloat(n.getAttribute("y") || 0);
                const bz = parseFloat(n.getAttribute("z") || 0);
                const r = parseFloat(n.getAttribute("r") || 255);
                const g = parseFloat(n.getAttribute("g") || 255);
                const b = parseFloat(n.getAttribute("b") || 255);
                const alpha = parseFloat(n.getAttribute("alpha") || 1.0);
                const texName = n.getAttribute("TextureName") || "";
                
                const wrapStr = n.getAttribute("WrapOnSides");
                const wrap = wrapStr ? parseInt(wrapStr) : 6;

                const mat = new THREE.MeshStandardMaterial({ 
                    color: new THREE.Color(r/255, g/255, b/255), 
                    transparent: alpha < 1 || Boolean(texName),
                    opacity: alpha
                });

                let geom = baseGeometry;
                if (texName) {
                    assignSharedTexture(mat, texName);
                    
                    if (wrap === 1) {
                        geom = getWrappedBaseGeometry();
                    }
                }

                const mesh = new THREE.Mesh(geom, mat);
                mesh.position.set(bx, by, bz);
                mesh.name = cubename;
                mesh.userData.textureName = texName;
                mesh.userData.wrap = wrap;
                axisGroup.add(mesh);
                collisionVoxels.push({
                    cubename,
                    x: bx,
                    y: by,
                    z: bz
                });
            }

            let collisionData = objectCollisionDataCache.get(`xml:${url}`);
            if (!collisionData) {
                collisionData = buildObjectCollisionData(collisionVoxels);
                if (collisionData) {
                    objectCollisionDataCache.set(`xml:${url}`, collisionData);
                }
            }
            if (collisionData) {
                objectVoxelCollisionData.set(axisGroup, collisionData);
                objectCollisionLocalBoxes.set(
                    axisGroup,
                    collisionData.localBounds
                );
            }
            axisGroup.userData.loaded = true;
            return axisGroup;
        })
        .catch(err => {
            axisGroup.userData.loadError = err.message;
            setDebugError(`Cannot fetch ${url}. (Use HTTP Server)`);
            console.error(err);
            return axisGroup;
        });

    return axisGroup;
};

function createInlineMapCube(entry, instanceName) {
    const group = new THREE.Group();
    group.name = instanceName;
    group.userData.loaded = true;
    injectUnityCompatibility(group);

    let color = new THREE.Color(0x888888);
    if (Array.isArray(entry.color) && entry.color.length >= 3) {
        color = new THREE.Color(
            Number(entry.color[0]) / 255,
            Number(entry.color[1]) / 255,
            Number(entry.color[2]) / 255
        );
    } else if (entry.color !== undefined) {
        color = new THREE.Color(entry.color);
    }

    const opacity = entry.alpha !== undefined ? Number(entry.alpha) : 1;
    const material = new THREE.MeshStandardMaterial({
        color,
        transparent: opacity < 1,
        opacity
    });
    const mesh = new THREE.Mesh(baseGeometry, material);
    mesh.name = entry.voxelName || `${instanceName}_voxel`;
    group.add(mesh);
    const localBounds = new THREE.Box3(
        new THREE.Vector3(-0.5, -0.5, -0.5),
        new THREE.Vector3(0.5, 0.5, 0.5)
    );
    const collisionData = {
        localBounds,
        tree: { bounds: localBounds, boxes: [localBounds] }
    };
    objectVoxelCollisionData.set(group, collisionData);
    objectCollisionLocalBoxes.set(group, localBounds);

    scene.add(group);
    cubes.push(group);
    group.userData.ready = Promise.resolve(group);
    return group;
}

function opaqueMapMaterialSignature(mesh) {
    const material = mesh.material;
    if (!mesh.visible || !material || material.visible === false ||
        Array.isArray(material) || material.transparent || material.opacity < 1) {
        return '';
    }

    return [
        mesh.geometry.uuid,
        material.type,
        material.color?.getHexString() || '',
        material.map?.uuid || '',
        material.roughness ?? '',
        material.metalness ?? '',
        material.side,
        material.alphaTest,
        material.depthTest,
        material.depthWrite,
        mesh.castShadow,
        mesh.receiveShadow,
        mesh.layers.mask,
        mesh.renderOrder
    ].join('|');
}

const mapPartCompatibilityMethods = [
    'traverse',
    'traverseVisible',
    'getObjectById',
    'getObjectByName',
    'getObjectByProperty',
    'getObjectsByProperty',
    'raycast',
    'clone',
    'copy',
    'toJSON',
    'remove',
    'clear'
];

function restoreMapPartCompatibilityMethods(part, state) {
    Object.entries(state.originalMethods).forEach(([name, record]) => {
        if (record.hadOwn) {
            part[name] = record.value;
        } else {
            delete part[name];
        }
    });
}

function installMapPartCompatibilityMethods(part, state) {
    mapPartCompatibilityMethods.forEach(name => {
        const method = part[name];
        if (typeof method !== 'function') return;
        state.originalMethods[name] = {
            hadOwn: Object.prototype.hasOwnProperty.call(part, name),
            value: method
        };
        part[name] = function(...args) {
            disableMapPartRenderBatches(this);
            return method.apply(this, args);
        };
    });
}

function disableMapPartRenderBatches(part) {
    const state = mapRenderBatchStates.get(part);
    if (!state) return;

    // Restore public methods first so disposal/material functions see only
    // the original hierarchy and batch removal cannot re-enter this function.
    restoreMapPartCompatibilityMethods(part, state);
    state.originalMeshes.forEach(record => {
        record.mesh.visible = record.visible;
        record.mesh.matrixAutoUpdate = record.matrixAutoUpdate;
        record.mesh.matrixWorldAutoUpdate = record.matrixWorldAutoUpdate;
        record.mesh.updateMatrix();
        record.mesh.updateWorldMatrix(true, false);
        delete record.mesh.userData.mapRenderBatched;
    });

    state.batches.forEach(batch => {
        batch.removeFromParent();
        if (batch.material.map &&
            batch.material.map.userData.threeDPLMaterials) {
            batch.material.map.userData.threeDPLMaterials.delete(
                batch.material
            );
        }
        batch.material.dispose();
        batch.dispose();
    });

    mapRenderBatchStates.delete(part);
    part.userData.mapRenderOptimizationDisabled = true;
    // Traversing a map part exposes its voxels for editing. From this point,
    // collision tests use the live hierarchy so a moved/deleted voxel cannot
    // be masked by the immutable JSON collision tree built at load time.
    part.userData.collisionCacheDisabled = true;
    objectVoxelCollisionData.delete(part);
    objectCollisionLocalBoxes.delete(part);
}

function optimizeMapPartRendering(part) {
    if (part.userData.mapRenderOptimizationDisabled ||
        mapRenderBatchStates.has(part)) {
        return;
    }

    const groups = new Map();
    part.traverse(child => {
        if (!child.isMesh || child.isInstancedMesh) return;
        const signature = opaqueMapMaterialSignature(child);
        if (!signature) return;
        if (!groups.has(signature)) groups.set(signature, []);
        groups.get(signature).push(child);
    });

    const batchGroups = [...groups.values()].filter(meshes => meshes.length > 1);
    if (batchGroups.length === 0) return;

    part.updateWorldMatrix(true, true);
    const inversePartMatrix = new THREE.Matrix4()
        .copy(part.matrixWorld)
        .invert();
    const instanceMatrix = new THREE.Matrix4();
    const originalMeshes = [];
    const batches = [];

    batchGroups.forEach(meshes => {
        const firstMesh = meshes[0];
        const batchMaterial = firstMesh.material.clone();
        if (batchMaterial.map &&
            batchMaterial.map.userData.threeDPLHasTransparentPixels ===
                undefined) {
            if (!batchMaterial.map.userData.threeDPLMaterials) {
                batchMaterial.map.userData.threeDPLMaterials = new Set();
            }
            batchMaterial.map.userData.threeDPLMaterials.add(batchMaterial);
        }

        const batch = new THREE.InstancedMesh(
            firstMesh.geometry,
            batchMaterial,
            meshes.length
        );
        meshes.forEach((mesh, index) => {
            instanceMatrix.multiplyMatrices(
                inversePartMatrix,
                mesh.matrixWorld
            );
            batch.setMatrixAt(index, instanceMatrix);
            originalMeshes.push({
                mesh,
                visible: mesh.visible,
                matrixAutoUpdate: mesh.matrixAutoUpdate,
                matrixWorldAutoUpdate: mesh.matrixWorldAutoUpdate
            });
            mesh.updateMatrix();
            mesh.matrixAutoUpdate = false;
            mesh.matrixWorldAutoUpdate = false;
            mesh.visible = false;
            mesh.userData.mapRenderBatched = true;
        });

        batch.instanceMatrix.needsUpdate = true;
        batch.computeBoundingBox();
        batch.computeBoundingSphere();
        batch.name = '__3dpl_map_render_batch__';
        batch.userData.mapRenderBatch = true;
        batch.castShadow = firstMesh.castShadow;
        batch.receiveShadow = firstMesh.receiveShadow;
        batch.layers.mask = firstMesh.layers.mask;
        batch.renderOrder = firstMesh.renderOrder;
        part.add(batch);
        batches.push(batch);
    });

    const state = {
        originalMeshes,
        batches,
        originalMethods: {}
    };
    mapRenderBatchStates.set(part, state);
    installMapPartCompatibilityMethods(part, state);
}

function scheduleMapRenderingOptimization(mapGroup, mapParts) {
    const textureReadyPromises = mapPartTextureReadyPromises(mapParts);

    Promise.all([...textureReadyPromises]).then(() => {
        if (!cubes.includes(mapGroup) || mapGroup.userData.cancelled) return;
        mapParts.forEach(part => optimizeMapPartRendering(part));
        mapGroup.userData.renderOptimized = true;
    });
}

function mapPartTextureReadyPromises(mapParts) {
    const textureReadyPromises = new Set();
    mapParts.forEach(part => {
        part.traverse(child => {
            if (!child.isMesh || !child.material) return;
            const materials = Array.isArray(child.material)
                ? child.material
                : [child.material];
            materials.forEach(material => {
                const ready = material.map?.userData.threeDPLReady;
                if (ready) textureReadyPromises.add(ready);
            });
        });
    });
    return textureReadyPromises;
}

function scheduleMapEditorObjectOptimization(object) {
    const textureReadyPromises = mapPartTextureReadyPromises([object]);
    Promise.all([...textureReadyPromises]).then(() => {
        if (!mapEditorMode ||
            !cubes.includes(object) ||
            !object.userData.mapEditorObject ||
            object.userData.cancelled ||
            object.userData.loadError) {
            return;
        }
        optimizeMapPartRendering(object);
    });
}

/**
 * Loads a JSON map exported by the Creative Mode Map Editor.
 * The returned group is registered like any other 3DPL object, so its
 * objectName can be passed to mv(), sp(), rt(), sr(), sc(), dl(), cd(), etc.
 * Local filenames are loaded from the Maps/ subdirectory.
 */
window.LoadMap = function(mapJsonFile, objectName) {
    if (!mapJsonFile) {
        setDebugError('LoadMap Error: Missing map JSON filename.');
        return null;
    }

    // Accept an optional leading "Maps/", but never load map files from
    // outside that directory.
    let normalizedMapFile = String(mapJsonFile).trim().replace(/\\/g, '/');
    while (normalizedMapFile.startsWith('./')) {
        normalizedMapFile = normalizedMapFile.substring(2);
    }
    const relativeMapFile = /^\/Maps\//i.test(normalizedMapFile)
        ? normalizedMapFile.substring('/Maps/'.length)
        : (/^Maps\//i.test(normalizedMapFile)
            ? normalizedMapFile.substring('Maps/'.length)
            : normalizedMapFile);

    if (!relativeMapFile ||
        relativeMapFile.startsWith('/') ||
        relativeMapFile.split('/').includes('..') ||
        !relativeMapFile.toLowerCase().endsWith('.json')) {
        setDebugError('LoadMap Error: The map must be a JSON file inside Maps/.');
        return null;
    }

    const mapUrl = `Maps/${relativeMapFile}`;

    objectName = objectName || (`map_${Math.random().toString(36).substr(2, 5)}`);

    const mapGroup = new THREE.Group();
    mapGroup.name = objectName;
    mapGroup.userData.mapFile = relativeMapFile;
    mapGroup.userData.is3DPLMap = true;
    mapGroup.userData.loaded = false;
    injectUnityCompatibility(mapGroup);
    scene.add(mapGroup);
    cubes.push(mapGroup);

    const fetchMapJSON = async () => {
        const response = await fetch(mapUrl, { cache: 'no-store' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);
        return response.json();
    };

    mapGroup.userData.ready = fetchMapJSON()
        .then((data) => {
            // Do not resurrect a map that was deleted while its file was loading.
            if (!cubes.includes(mapGroup)) return mapGroup;

            const entries = normalizeMapDocument(data);
            const mapParts = entries.map((entry, index) => {
                const part = instantiateMapEntry(
                    entry,
                    `${objectName}_part_${index + 1}`
                );

                // Only the parent map should be registered as a top-level 3DPL object.
                const partIndex = cubes.indexOf(part);
                if (partIndex !== -1) cubes.splice(partIndex, 1);
                mapGroup.add(part);
                part.userData.mapEntry = entry;
                return part;
            });

            mapGroup.userData.mapEntries = entries;
            mapGroup.userData.resolvedMapUrl = mapUrl;
            return Promise.all(mapParts.map(part => part.userData.ready))
                .then(() => {
                    if (!cubes.includes(mapGroup)) return mapGroup;

                    const failedPart = mapParts.find(part => part.userData.loadError);
                    if (failedPart) {
                        mapGroup.userData.missingObjectFile =
                            requiredMapObjectFilename(failedPart);
                        throw new Error(requiredMapObjectError(failedPart));
                    }

                    // Build one shared local collision tree per unique source
                    // file during loading, rather than pausing the first drive
                    // frame to scan every voxel in every repeated instance.
                    mapParts.forEach(part => ensureMapItemCollisionData(part));

                    // Collision checks can now safely inspect every part's voxels.
                    mapGroup.userData.loaded = true;
                    scheduleMapRenderingOptimization(mapGroup, mapParts);
                    return mapGroup;
                });
        })
        .catch(error => {
            mapGroup.userData.loadError = error.message;
            setDebugError(`LoadMap Error: ${mapUrl} - ${error.message}`);
            console.error('LoadMap Error:', error);
            return mapGroup;
        });

    return mapGroup;
};

// --- Object Editor File I/O ---
document.getElementById('btn-save-json').onclick = () => {
    const exportData = [];
    cubes.forEach(c => {
        if (c.name === "AxisPoint" || c.name === "guide" || c.name === "pointer") return;
        if (c.material) {
            exportData.push({
                cubename: c.name,
                x: c.position.x, y: c.position.y, z: c.position.z,
                r: Math.round(c.material.color.r * 255), 
                g: Math.round(c.material.color.g * 255), 
                b: Math.round(c.material.color.b * 255),
                alpha: c.material.opacity,
                TextureName: c.userData.textureName || "",
                WrapOnSides: c.userData.wrap || 6
            });
        }
    });
    
    const blob = new Blob([JSON.stringify(exportData, null, 2)], {type: "application/json"});
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url; 
    a.download = "my_object.json"; 
    a.click();
    URL.revokeObjectURL(url);
};

document.getElementById('btn-load-json').onclick = () => document.getElementById('file-input-json').click();
document.getElementById('file-input-json').onchange = (e) => {
    const file = e.target.files[0];
    if(!file) return;
    const reader = new FileReader();
    reader.onload = (event) => {
        try {
            const data = JSON.parse(event.target.result);
            window.cs(); 
            const axis = window.qb("AxisPoint", 0, 0, 0);
            axis.material.color.setHex(0x00ff00);
            axis.material.opacity = 0.5;

            data.forEach(cords => {
                const c = window.qb(cords.cubename, cords.x, cords.y, cords.z);
                c.material.color.setRGB(cords.r/255, cords.g/255, cords.b/255);
                c.material.opacity = cords.alpha !== undefined ? cords.alpha : 1.0;
                
                const wrap = cords.WrapOnSides || 6;
                if(cords.TextureName) {
                    window.tx(cords.cubename, cords.TextureName, "File", wrap);
                }
            });
            e.target.value = '';
        } catch(err) { console.error("Error loading JSON", err); }
    };
    reader.readAsText(file);
};

document.getElementById('btn-import-xml').onclick = () => document.getElementById('file-input-xml').click();
document.getElementById('file-input-xml').onchange = (e) => {
    const file = e.target.files[0];
    if(!file) return;
    const reader = new FileReader();
    reader.onload = (event) => {
        try {
            const parser = new DOMParser();
            const xmlDoc = parser.parseFromString(event.target.result, "text/xml");
            let nodes = xmlDoc.getElementsByTagName("Coordinates");
            if(nodes.length === 0) nodes = xmlDoc.getElementsByTagName("Object");
            
            window.cs(); 
            const axis = window.qb("AxisPoint", 0, 0, 0);
            axis.material.color.setHex(0x00ff00);
            axis.material.opacity = 0.5;

            for(let i=0; i<nodes.length; i++) {
                const n = nodes[i];
                const cubename = n.getAttribute("cubename") || generateId();
                if (cubename === "AxisPoint" || cubename.startsWith("collider")) continue;

                const x = parseFloat(n.getAttribute("x") || 0);
                const y = parseFloat(n.getAttribute("y") || 0);
                const z = parseFloat(n.getAttribute("z") || 0);
                const r = parseFloat(n.getAttribute("r") || 255);
                const g = parseFloat(n.getAttribute("g") || 255);
                const b = parseFloat(n.getAttribute("b") || 255);
                const alpha = parseFloat(n.getAttribute("alpha") || 1.0);
                const texName = n.getAttribute("TextureName") || "";
                
                const wrapStr = n.getAttribute("WrapOnSides");
                const wrap = wrapStr ? parseInt(wrapStr) : 6;
                
                const c = window.qb(cubename, x, y, z);
                c.material.color.setRGB(r/255, g/255, b/255);
                c.material.opacity = alpha;
                if(texName) {
                    window.tx(cubename, texName, "File", wrap);
                }
            }
            e.target.value = '';
        } catch(err) { console.error("Error loading XML", err); }
    };
    reader.readAsText(file);
};

function PreProcessor(code) {
    let processed = code;
    processed = processed.replace(/\bMap\s*\(/g, "XMLObj(");

    processed = processed.replace(/([a-zA-Z0-9_\[\]"']+)(?:\.transform)?\.parent\s*=\s*([a-zA-Z0-9_\[\]"']+)(?:\.transform)?;/g, "$2.attach($1);");
    processed = processed.replace(/\.transform\b/g, "");

    processed = processed.replace(/(for\s*\([^)]+\)\s*)([^{\s][^;]+;?)/g, "$1 { $2 }");
    processed = processed.replace(/(while\s*\([^)]+\)\s*)([^{\s][^;]+;?)/g, "$1 { $2 }");

    const loopCheck = `if (performance.now() - window._evalStartTime > 500) { throw new Error("Loop timeout! Infinite loop prevented."); } `;
    processed = processed.replace(/(for\s*\(.*?\)\s*\{)/g, "$1 " + loopCheck);
    processed = processed.replace(/(while\s*\(.*?\)\s*\{)/g, "$1 " + loopCheck);

    return processed;
}

// The update editor usually stays unchanged for thousands of frames. Cache
// its preprocessed form and refresh it only when CodeMirror reports an edit.
// Direct eval is retained so update programs keep the exact same scope and
// behavior they had before this optimization.
let cachedUpdateCode = PreProcessor(editorUpdate.getValue());

function refreshCachedUpdateCode() {
    cachedUpdateCode = PreProcessor(editorUpdate.getValue());
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

// --- ORIGINAL TUTORIALS + CAR SIMULATOR LESSONS ---
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
        upd: `//Use arrow keys and [A] and [Z] and Left Control\nif (Input.GetKey (KeyCode.RightArrow))\n    rt("camera",0,2,0);\nif (Input.GetKey (KeyCode.LeftArrow))\n    rt("camera",0,-2,0);\nif (Input.GetKey (KeyCode.UpArrow))\n    rt("camera",2,0,0);\nif (Input.GetKey (KeyCode.DownArrow))\n    rt("camera",-2,0,0);\nif (Input.GetKey (KeyCode.A))\n    mv("camera",0,0,0.5);\nif (Input.GetKey (KeyCode.Z))\n    mv("camera",0,0,-0.5);\nif (Input.GetKey (KeyCode.LeftControl)) {\n    qb("bullet",0,0,0);\n    eq("bullet","camera"); // Takes rotation so local mv works\n}\nif (ex("bullet")) {\n   mv("bullet",0,0,0.5);\n}\nif(cd("bullet","Asteroid0")) \n    dl("Asteroid0");\nif(cd("bullet","Asteroid1")) \n    dl("Asteroid1");\nif(cd("bullet","Asteroid2")) \n    dl("Asteroid2");\nif(cd("bullet","Asteroid3")) \n    dl("Asteroid3");`
    },
    18: {
        decl: `//First person 3D platformer\n// Optimized floor to avoid lagging web browsers with 400 cubes\nqb("floor", 0, -5, 0);\nsc("floor", 40, 1, 40);\ncl("floor", Color.green);\n\n//trees\nfunction drawtree(x,y,z) {\n    var Brown = 0x8b45be; //Translated from Color(139,69,190)\n    qb("trunk",x,y,z);\n    qb("trunk",x,y+1,z);\n    qb("trunk",x,y+2,z);\n    qb("trunk",x,y+3,z);\n    cl("trunk", Brown);\n    qb("bush",x,y+4,z);\n    qb("bush",x+1,y+4,z);\n    qb("bush",x-1,y+4,z);\n    cl("bush", Color.green);\n}\n//trees in front\ndrawtree(0,-4,0);\ndrawtree(5,-4,5);\ndrawtree( -5,-4,15);\n//trees behind\ndrawtree(-3,-4,-10);\ndrawtree(-10,-4,-10);\ndrawtree(5,-4,-15);\n\nvars["gforce"]=-0.1;\nvars["isJumping"]=false;`,
        upd: `//Use arrow keys to move, Left Control to jump\n//Implement gravity\nif(!cd("camera","floor") && !cd("camera","bush") && !vars["isJumping"]) {\n    mv("camera",0,vars["gforce"],0);\n}\nvars["isJumping"] = false;\n\n//Get user input\nif (Input.GetKey (KeyCode.RightArrow))\n    rt("camera",0,2,0);\nif (Input.GetKey (KeyCode.LeftArrow))\n    rt("camera",0,-2,0);\nif (Input.GetKey (KeyCode.LeftControl)) {\n    mv("camera",0,0.3,0);\n    vars["isJumping"] = true;\n}\nif (Input.GetKey (KeyCode.UpArrow) && !cd("camera","trunk"))\n    mv("camera",0,0,0.2);\nif (Input.GetKey (KeyCode.DownArrow))\n    mv("camera",0,0,-0.2);`
    },
    19: {
        title: "tx: textures",
        decl: `// tx(name, textureFile, source, wrap) adds a texture.\n// name: object name, textureFile: a file in Textures/.\n// source is kept for old 3DPL code; the web engine loads local files.\n// wrap 6 repeats one image on every face.\n// wrap 1 uses a 3-by-2 six-sided texture layout.\nqb("texturedCube", 0, 0, 0);\ntx("texturedCube", "stone1.png", "File", 6);`,
        upd: ``
    },
    20: {
        title: "alpha: transparency",
        decl: `// alpha(name, value) changes opacity.\n// 1 is solid, 0 is invisible, and values between are transparent.\nqb("solid", -2, 0, 0);\nqb("glass", 0, 0, 0);\nqb("faint", 2, 0, 0);\ncl("solid", Color.blue);\ncl("glass", Color.blue);\ncl("faint", Color.blue);\nalpha("solid", 1);\nalpha("glass", 0.5);\nalpha("faint", 0.15);`,
        upd: ``
    },
    21: {
        title: "sp, mv, rt, and sr",
        decl: `// The Car Simulator uses four transform functions.\nqb("car", 0, 0, 0);\nsc("car", 3, 1, 5); // sc sets x, y, z size multipliers.\ncl("car", Color.red);\n\n// sp sets an exact world position. cp is another name for sp.\nsp("car", 0, 0, 0);\n\n// sr sets an exact rotation in degrees. an is another name for sr.\nsr("car", 0, 25, 0);`,
        upd: `// mv moves relative to the object's current local direction.\n// rt adds this many degrees to the current rotation.\nrt("car", 0, 0.5, 0);\nmv("car", 0, 0, 0.02);`
    },
    22: {
        title: "Obj and XMLObj",
        decl: `// Obj(jsonFile, instanceName, x, y, z) loads JSON voxel art.\nvars["car"] = Obj("sports-car.json", "car", -3, 0, 0);\nsc("car", 0.19, 0.19, 0.19);\n\n// XMLObj(xmlFile, instanceName, x, y, z) loads legacy XML art.\n// Map(...) in old programs is automatically translated to XMLObj(...).\nvars["tree"] = XMLObj("1tree.xml", "tree", 3, -2, 0);\n\n// Both loaders return a group immediately; its voxels load in the background.`,
        upd: `rt("car", 0, 0.5, 0);\nrt("tree", 0, -0.25, 0);`
    },
    23: {
        title: "vars and transform",
        decl: `// vars keeps values and object references available every frame.\nvars["car"] = Obj("sports-car.json", "car", 0, 0, 0);\nsc("car", 0.19, 0.19, 0.19);\nvars["speed"] = 0.05;\n\n// Loaded objects have Unity-style transform and position properties.\n// Read coordinates with vars["car"].transform.position.x, .y, and .z.`,
        upd: `mv("car", vars["speed"], 0, 0);\n\n// Turn around when the car reaches either side.\nif (Math.abs(vars["car"].transform.position.x) > 4) {\n    vars["speed"] = -vars["speed"];\n}`
    },
    24: {
        title: "parenting objects",
        decl: `// Parenting makes one object follow another.\nvars["car"] = Obj("sports-car.json", "car", 0, 0, 0);\nsc("car", 0.19, 0.19, 0.19);\nvars["tire"] = Obj("sports-car-tires.json", "tire", 0, 0, 0);\nsc("tire", 0.10, 0.10, 0.10);\n\n// The preprocessor converts this legacy .transform.parent syntax\n// into Three.js parenting while keeping the tire's world position.\nvars["tire"].transform.parent = vars["car"].transform;`,
        upd: `// Both the car and its child tire now move together.\nrt("car", 0, 0.5, 0);`
    },
    25: {
        title: "traverse loaded voxels",
        decl: `// traverse(function(obj) {...}) visits a group and all its children.\nvars["car"] = Obj("sports-car.json", "car", 0, 0, 0);\nsc("car", 0.19, 0.19, 0.19);\nvars["painted"] = false;`,
        upd: `// Obj loads asynchronously, so keep checking until meshes exist.\nif (!vars["painted"]) {\n    vars["car"].traverse(function(obj) {\n        if (obj.isMesh) {\n            obj.material.color.setHex(Color.yellow);\n            if (!vars["painted"])\n                console.log("First loaded voxel:", obj.name, obj.position);\n            vars["painted"] = true;\n        }\n    });\n}`
    },
    26: {
        title: "car audio",
        decl: `// AttachSound(objectName, fileName) loads a file from Sounds/.\n// PlaySoundLoop(objectName) starts it and repeats it.\n// SetVolume(objectName, value) uses 0 for silent and 1 for full volume.\n// Audio must start after the user presses START / STOP.\n\n// These are the exact calls used by Car Simulator 6:\n// AttachSound("car0", "car.wav");\n// PlaySoundLoop("car0");\n// AttachSound("camera", "NOW7.wav");\n// PlaySoundLoop("camera");\n// SetVolume("camera", 0.1);\n\n// They are commented because this project currently has no Sounds folder.\nqb("car0", 0, 0, 0);\nsc("car0", 3, 1, 5);\ncl("car0", Color.red);`,
        upd: ``
    },
    27: {
        title: "Input.GetKey driving",
        decl: `// Input.GetKey(KeyCode...) is true while a key is held.\nqb("car", 0, 0, 0);\nsc("car", 2, 1, 4);\ncl("car", Color.red);`,
        upd: `// Hold Up/Down to drive and Left/Right to steer.\nif (Input.GetKey(KeyCode.UpArrow))\n    mv("car", 0, 0, 0.1);\nif (Input.GetKey(KeyCode.DownArrow))\n    mv("car", 0, 0, -0.1);\nif (Input.GetKey(KeyCode.LeftArrow))\n    rt("car", 0, -2, 0);\nif (Input.GetKey(KeyCode.RightArrow))\n    rt("car", 0, 2, 0);`
    },
    28: {
        title: "is_touching_voxel",
        decl: `// is_touching_voxel(land, colliders, colliderName) checks one\n// named collider mesh against every voxel mesh in a map or obstacle.\nvars["wall"] = qb("wall", 0, 2, 0);\nsc("wall", 6, 6, 1);\ncl("wall", Color.green);\n\n// collider_front is 10 units in front of this moving collider group.\nvars["colliders"] = Obj("car_colliders.json", "colliders", 0, 0, -12);\nvars["colliderSpeed"] = 0.08;\nsp("camera", 10, 8, 24);\nan("camera", -15, 25, 0);`,
        upd: `// The collider moves automatically after START is clicked.\nmv("colliders", 0, 0, -vars["colliderSpeed"]);\n\nvar touching = is_touching_voxel(\n    vars["wall"], vars["colliders"], "collider_front");\n\n// The wall turns red whenever collider_front touches it.\ncl("wall", touching ? Color.red : Color.green);\n\n// Reverse so the demonstration repeats.\nif (vars["colliders"].transform.position.z > 2)\n    vars["colliderSpeed"] = -0.08;\nif (vars["colliders"].transform.position.z < -12)\n    vars["colliderSpeed"] = 0.08;`
    },
    29: {
        title: "reusable simulator functions",
        decl: `// JavaScript functions group repeated simulator instructions.\nvars["car"] = qb("car", 0, 0, 0);\nsc("car", 2, 1, 4);\ncl("car", Color.red);\nvars["tire"] = qb("tire", 1.2, -0.6, 1);\nsc("tire", 0.5, 0.5, 0.5);\ncl("tire", Color.black);\nvars["tire"].transform.parent = vars["car"].transform;\n\n// Store functions in vars when the update editor must call them.\nvars["move_camera"] = function() {\n    sp("camera",\n        vars["car"].transform.position.x,\n        vars["car"].transform.position.y + 3,\n        vars["car"].transform.position.z + 10);\n};\n\nvars["rotate_tires"] = function() {\n    rt("tire", 10, 0, 0);\n};\n\nvars["drive"] = function(distance) {\n    mv("car", 0, 0, distance);\n    vars["rotate_tires"]();\n};`,
        upd: `if (Input.GetKey(KeyCode.UpArrow))\n    vars["drive"](0.1);\nif (Input.GetKey(KeyCode.DownArrow))\n    vars["drive"](-0.1);\n\n// Run camera follow every frame, as Car Simulator 6 does.\nvars["move_camera"]();`
    },
    30: {
        title: "Full Car Simulator 6",
        decl: `// FULL CAR SIMULATOR 6\n// Arrow keys drive and steer. Space launches the car upward.\nvars["land"] = XMLObj("3DLP_MAP_ISLAND_extended.xml", "land", 0, 0, 0);\nvars["car"] = Obj("sports-car.json", "car0", -2, 0, -10);\nvars["car_colliders"] = Obj("car_colliders.json", "car_colliders", 0, 0, 0);\nvars["car_colliders"].transform.parent = vars["car"].transform;\n\nvars["car_colliders"].traverse(function(obj) {\n    if (obj.isMesh)\n        console.log("CAR COLLIDER:", obj.name, obj.position);\n});\n\nsr("land", 0, 180, 0);\nmv("land", 0, 0, -20);\nsc("car0", 0.19, 0.19, 0.19);\n\nvars["tire1"] = Obj("sports-car-tires.json", "tire1", 0, 0, 0);\nvars["tire2"] = Obj("sports-car-tires.json", "tire2", 0, 0, 0);\nvars["tire3"] = Obj("sports-car-tires.json", "tire3", 0, 0, 0);\nvars["tire4"] = Obj("sports-car-tires.json", "tire4", 0, 0, 0);\nsc("tire1", 0.10, 0.10, 0.10);\nsc("tire2", 0.10, 0.10, 0.10);\nsc("tire3", 0.10, 0.10, 0.10);\nsc("tire4", 0.10, 0.10, 0.10);\n\nsp("tire1", vars["car"].transform.position.x-0.7, vars["car"].transform.position.y, vars["car"].transform.position.z-1);\nsp("tire2", vars["car"].transform.position.x+0.7, vars["car"].transform.position.y, vars["car"].transform.position.z-1);\nsp("tire3", vars["car"].transform.position.x-0.7, vars["car"].transform.position.y, vars["car"].transform.position.z+1);\nsp("tire4", vars["car"].transform.position.x+0.7, vars["car"].transform.position.y, vars["car"].transform.position.z+1);\n\nvars["tire1"].transform.parent = vars["car"].transform;\nvars["tire2"].transform.parent = vars["car"].transform;\nvars["tire3"].transform.parent = vars["car"].transform;\nvars["tire4"].transform.parent = vars["car"].transform;\n\n// Uncomment these when car.wav and NOW7.wav exist in Sounds/.\n// AttachSound("car0", "car.wav");\n// PlaySoundLoop("car0");\n// AttachSound("camera", "NOW7.wav");\n// PlaySoundLoop("camera");\n// SetVolume("camera", 0.1);\n\n// Functions are stored in vars so declarations and update share them.\nvars["move_camera"] = function() {\n    sp("camera", vars["car"].transform.position.x,\n        vars["car"].transform.position.y,\n        vars["car"].transform.position.z);\n    mv("camera", 3, 3, -30);\n};\n\nvars["rotate_tires"] = function() {\n    sr("tire1", vars["tire1"].transform.eulerAngles.x+45, vars["tire1"].transform.eulerAngles.y, vars["tire1"].transform.eulerAngles.z);\n    sr("tire2", vars["tire2"].transform.eulerAngles.x+45, vars["tire2"].transform.eulerAngles.y, vars["tire2"].transform.eulerAngles.z);\n    sr("tire3", vars["tire3"].transform.eulerAngles.x+45, vars["tire3"].transform.eulerAngles.y, vars["tire3"].transform.eulerAngles.z);\n    sr("tire4", vars["tire4"].transform.eulerAngles.x+45, vars["tire4"].transform.eulerAngles.y, vars["tire4"].transform.eulerAngles.z);\n};`,
        upd: `// Get user input.\nif (Input.GetKey(KeyCode.RightArrow)) {\n    rt("car0", 0, 3, 0);\n    rt("camcol", 0, 3, 0);\n}\nif (Input.GetKey(KeyCode.LeftArrow)) {\n    rt("car0", 0, -3, 0);\n    rt("camcol", 0, -3, 0);\n}\nif (Input.GetKey(KeyCode.Space)) {\n    mv("car0", 0, 100, 0);\n    mv("camcol", 0, 100, 0);\n}\n\nif (Input.GetKey(KeyCode.DownArrow) &&\n    !is_touching_voxel(vars["land"], vars["car_colliders"], "collider_back")) {\n    mv("car0", 0, 0, 1);\n    mv("camcol", 0, 0, 1);\n    vars["rotate_tires"]();\n}\n\nif (Input.GetKey(KeyCode.UpArrow) &&\n    !is_touching_voxel(vars["land"], vars["car_colliders"], "collider_front")) {\n    mv("car0", 0, 0, -1);\n    mv("camcol", 0, 0, -1);\n    vars["rotate_tires"]();\n}\n\n// Follow the car every frame.\nvars["move_camera"]();`
    },
    31: {
        title: "Helicopet Flight Simulator 3",
        decl: "// HELICOPET FLIGHT SIMULATOR 3 - converted for 3DPL Web\n// W/S: up/down, A/D: turn, arrow keys: forward/back/sideways.\n\n// Use the same island as Car Simulator 6.\nvars[\"land\"] = XMLObj(\"3DLP_MAP_ISLAND_extended.xml\", \"land\", 0, 0, 0);\nsr(\"land\", 0, 180, 0);\nmv(\"land\", 0, 0, -20);\n\n// Load the complete helicopter and its separate rotor at the same origin.\nvars[\"helicopter\"] = Obj(\n    \"heli_no_proeller.json\", \"helicopter\", 0, 0, -10);\nvars[\"propeller\"] = Obj(\n    \"propeller.json\", \"propeller\", 0, 0, -10);\nsc(\"helicopter\", 0.25, 0.25, 0.25);\nsc(\"propeller\", 0.25, 0.25, 0.25);\nvars[\"propeller\"].transform.parent = vars[\"helicopter\"].transform;\n\n// Load four invisible collision probes, just like the car simulator.\nvars[\"helicopter_colliders\"] = Obj(\n    \"helicpter_colliders.json\", \"helicopter_colliders\", -2, 0, -10);\nsc(\"helicopter_colliders\", 0.25, 0.25, 0.25);\nvars[\"helicopter_colliders\"].transform.parent =\n    vars[\"helicopter\"].transform;\n\nvars[\"flightSpeed\"] = 1;\nvars[\"turnSpeed\"] = 5;\nvars[\"idleRotorSpeed\"] = 15\nvars[\"fastRotorSpeed\"] = 30\n\n// Follow from behind using the helicopter's current turning angle.\nvars[\"move_camera\"] = function() {\n    sp(\"camera\",\n        vars[\"helicopter\"].transform.position.x,\n        vars[\"helicopter\"].transform.position.y + 8,\n        vars[\"helicopter\"].transform.position.z);\n    sr(\"camera\", 18, vars[\"helicopter\"].transform.eulerAngles.y, 0);\n    mv(\"camera\", 3, 3, -30);\n};\n\n// Positive rt input becomes clockwise rotation in this web engine.\nvars[\"rotate_propeller\"] = function(amount) {\n    rt(\"propeller\", 0, amount, 0);\n};\n\n// Uncomment when these files exist in Sounds/.\n// AttachSound(\"helicopter\", \"helicopter.wav\");\n// PlaySoundLoop(\"helicopter\");\n// AttachSound(\"camera\", \"NOW1.wav\");\n// PlaySoundLoop(\"camera\");\n// SetVolume(\"camera\", 0.05);\n\nvars[\"move_camera\"]();",
        upd: "var speed = vars[\"flightSpeed\"];\nvar colliders = vars[\"helicopter_colliders\"];\nvar land = vars[\"land\"];\nvar goingUp = Input.GetKey(KeyCode.W);\nvar goingDown = Input.GetKey(KeyCode.S);\n\n// W goes up; the top probe prevents entering terrain from below.\nif (goingUp &&\n    !is_touching_voxel(land, colliders, \"collider_top\"))\n    mv(\"helicopter\", 0, speed, 0);\n\n// S goes down; the bottom probe prevents entering the ground.\nif (goingDown &&\n    !is_touching_voxel(land, colliders, \"collider_bottom\"))\n    mv(\"helicopter\", 0, -speed, 0);\n\n// A turns counterclockwise; D turns clockwise.\nif (Input.GetKey(KeyCode.A))\n    rt(\"helicopter\", 0, -vars[\"turnSpeed\"], 0);\nif (Input.GetKey(KeyCode.D))\n    rt(\"helicopter\", 0, vars[\"turnSpeed\"], 0);\n\n// Arrow keys move in the helicopter's local directions.\nif (Input.GetKey(KeyCode.UpArrow) &&\n    !is_touching_voxel(land, colliders, \"collider_front\"))\n    mv(\"helicopter\", 0, 0, speed);\nif (Input.GetKey(KeyCode.DownArrow) &&\n    !is_touching_voxel(land, colliders, \"collider_back\"))\n    mv(\"helicopter\", 0, 0, -speed);\nif (Input.GetKey(KeyCode.LeftArrow))\n    mv(\"helicopter\", -speed, 0, 0);\nif (Input.GetKey(KeyCode.RightArrow))\n    mv(\"helicopter\", speed, 0, 0);\n\n// The rotor always spins clockwise and speeds up during ascent/descent.\nvar rotorSpeed = (goingUp || goingDown)\n    ? vars[\"fastRotorSpeed\"]\n    : vars[\"idleRotorSpeed\"];\nvars[\"rotate_propeller\"](rotorSpeed);\nvars[\"move_camera\"]();"
    },
    32: {
        title: "Helicopter and LoadMap collisions",
        decl: `// TUTORIAL 32: HELICOPTER COLLISION WITH A JSON MAP
// W/S: up/down, A/D: turn, arrows: forward/back/sideways.
// The green floor and orange walls are loaded from Maps/.
vars["flight_map"] = LoadMap(
    "tutorial32_helicopter_map.json", "flight_map");

// Load the helicopter, rotor, and six invisible collision probes.
vars["helicopter"] = Obj(
    "heli_no_proeller.json", "helicopter", 0, 2, -8);
vars["propeller"] = Obj(
    "propeller.json", "propeller", 0, 2, -8);
vars["helicopter_colliders"] = Obj(
    "helicpter_colliders.json", "helicopter_colliders", 0, 2, -8);

sc("helicopter", 0.25, 0.25, 0.25);
sc("propeller", 0.25, 0.25, 0.25);
sc("helicopter_colliders", 0.25, 0.25, 0.25);
vars["propeller"].transform.parent = vars["helicopter"].transform;
vars["helicopter_colliders"].transform.parent =
    vars["helicopter"].transform;

vars["flightSpeed"] = 1;
vars["turnSpeed"] = 5;
vars["idleRotorSpeed"] = 15;
vars["fastRotorSpeed"] = 30;

vars["move_camera"] = function() {
    sp("camera",
        vars["helicopter"].transform.position.x,
        vars["helicopter"].transform.position.y + 8,
        vars["helicopter"].transform.position.z);
    sr("camera", 18, vars["helicopter"].transform.eulerAngles.y, 0);
    mv("camera", 3, 3, -30);
};

vars["rotate_propeller"] = function(amount) {
    rt("propeller", 0, amount, 0);
};

vars["move_camera"]();`,
        upd: `var speed = vars["flightSpeed"];
var goingUp = Input.GetKey(KeyCode.W);
var goingDown = Input.GetKey(KeyCode.S);

// Wait for both JSON files. This prevents movement through empty loading
// placeholders before their voxel meshes are available for collision.
var collisionReady =
    vars["flight_map"].userData.loaded &&
    vars["helicopter_colliders"].userData.loaded;

if (collisionReady) {
    // move_object_with_map_collision moves in 0.2-unit substeps and rolls
    // back the first substep that touches a voxel. This prevents tunneling.
    if (goingUp)
        move_object_with_map_collision(
            vars["flight_map"], vars["helicopter"],
            "collider_top", 0, speed, 0);
    if (goingDown)
        move_object_with_map_collision(
            vars["flight_map"], vars["helicopter"],
            "collider_bottom", 0, -speed, 0);

    if (Input.GetKey(KeyCode.UpArrow))
        move_object_with_map_collision(
            vars["flight_map"], vars["helicopter"],
            "collider_front", 0, 0, speed);
    if (Input.GetKey(KeyCode.DownArrow))
        move_object_with_map_collision(
            vars["flight_map"], vars["helicopter"],
            "collider_back", 0, 0, -speed);
    if (Input.GetKey(KeyCode.LeftArrow))
        move_object_with_map_collision(
            vars["flight_map"], vars["helicopter"],
            "collider_left", -speed, 0, 0);
    if (Input.GetKey(KeyCode.RightArrow))
        move_object_with_map_collision(
            vars["flight_map"], vars["helicopter"],
            "collider_right", speed, 0, 0);

    if (Input.GetKey(KeyCode.A))
        rt("helicopter", 0, -vars["turnSpeed"], 0);
    if (Input.GetKey(KeyCode.D))
        rt("helicopter", 0, vars["turnSpeed"], 0);
}

var rotorSpeed = (goingUp || goingDown)
    ? vars["fastRotorSpeed"]
    : vars["idleRotorSpeed"];
vars["rotate_propeller"](rotorSpeed);
vars["move_camera"]();`
    },
    33: {
        title: "Car Simulator 6 with LoadMap collisions",
        decl: `// TUTORIAL 33: CAR SIMULATOR 6 WITH A JSON MAP
// Arrow keys drive and steer. Space launches the car upward.
// THEMAP.json and every JSON object it references must be on the server.
vars["land"] = LoadMap("THEMAP.json", "land");
vars["car"] = Obj("sports-car.json", "car0", 2, 1, -10);
vars["car_colliders"] = Obj(
    "car_colliders.json", "car_colliders", 2, 1, -10);
vars["car_colliders"].transform.parent = vars["car"].transform;

sr("land", 0, 180, 0);
mv("land", 0, 0, -20);
sc("car0", 0.19, 0.19, 0.19);

vars["tire1"] = Obj("sports-car-tires.json", "tire1", 0, 0, 0);
vars["tire2"] = Obj("sports-car-tires.json", "tire2", 0, 0, 0);
vars["tire3"] = Obj("sports-car-tires.json", "tire3", 0, 0, 0);
vars["tire4"] = Obj("sports-car-tires.json", "tire4", 0, 0, 0);
sc("tire1", 0.10, 0.10, 0.10);
sc("tire2", 0.10, 0.10, 0.10);
sc("tire3", 0.10, 0.10, 0.10);
sc("tire4", 0.10, 0.10, 0.10);

sp("tire1", vars["car"].transform.position.x - 0.7,
    vars["car"].transform.position.y,
    vars["car"].transform.position.z - 1);
sp("tire2", vars["car"].transform.position.x + 0.7,
    vars["car"].transform.position.y,
    vars["car"].transform.position.z - 1);
sp("tire3", vars["car"].transform.position.x - 0.7,
    vars["car"].transform.position.y,
    vars["car"].transform.position.z + 1);
sp("tire4", vars["car"].transform.position.x + 0.7,
    vars["car"].transform.position.y,
    vars["car"].transform.position.z + 1);

vars["tire1"].transform.parent = vars["car"].transform;
vars["tire2"].transform.parent = vars["car"].transform;
vars["tire3"].transform.parent = vars["car"].transform;
vars["tire4"].transform.parent = vars["car"].transform;

// Six degrees per frame is twice the old steering speed of three.
vars["turnSpeed"] = 6;

vars["move_camera"] = function() {
    sp("camera",
        vars["car"].transform.position.x,
        vars["car"].transform.position.y,
        vars["car"].transform.position.z);
    mv("camera", 3, 3, -30);
};

vars["rotate_tires"] = function() {
    sr("tire1", vars["tire1"].transform.eulerAngles.x + 45,
        vars["tire1"].transform.eulerAngles.y,
        vars["tire1"].transform.eulerAngles.z);
    sr("tire2", vars["tire2"].transform.eulerAngles.x + 45,
        vars["tire2"].transform.eulerAngles.y,
        vars["tire2"].transform.eulerAngles.z);
    sr("tire3", vars["tire3"].transform.eulerAngles.x + 45,
        vars["tire3"].transform.eulerAngles.y,
        vars["tire3"].transform.eulerAngles.z);
    sr("tire4", vars["tire4"].transform.eulerAngles.x + 45,
        vars["tire4"].transform.eulerAngles.y,
        vars["tire4"].transform.eulerAngles.z);
};

// This safely advances in small steps and rolls back on a map collision.
vars["drive"] = function(distance, colliderName) {
    return move_object_with_map_collision(
        vars["land"], vars["car"], colliderName,
        0, 0, distance, 0.2);
};

vars["move_camera"]();`,
        upd: `// Steering remains responsive while the large map is loading.
if (Input.GetKey(KeyCode.RightArrow))
    rt("car0", 0, vars["turnSpeed"], 0);
if (Input.GetKey(KeyCode.LeftArrow))
    rt("car0", 0, -vars["turnSpeed"], 0);

// car_colliders is parented to the car, so it follows automatically.
// There is no camcol object in this program.
if (Input.GetKey(KeyCode.Space))
    mv("car0", 0, 1, 0);

// Wait until both asynchronous JSON loads have collision meshes.
var collisionReady =
    vars["land"].userData.loaded &&
    vars["car_colliders"].userData.loaded;

if (collisionReady) {
    if (Input.GetKey(KeyCode.DownArrow) &&
        vars["drive"](1, "collider_back"))
        vars["rotate_tires"]();

    if (Input.GetKey(KeyCode.UpArrow) &&
        vars["drive"](-1, "collider_front"))
        vars["rotate_tires"]();
}

// Follow the car every frame.
vars["move_camera"]();`
    }
};

const tutContainer = document.querySelector('.tutorial-row');
tutContainer.innerHTML = ''; 
let suppressDeclarationEvaluation = false;

for (let i = 1; i <= Object.keys(tutorials).length; i++) {
    const btn = document.createElement('button');
    btn.id = `tut-${i}`;
    btn.innerText = `${i}`;
    btn.title = tutorials[i].title || `Tutorial ${i}`;
    btn.onclick = () => loadTutorial(i);
    tutContainer.appendChild(btn);
}

const loadTutorial = (num) => {
    if (document.activeElement) document.activeElement.blur(); 
    isExecuting = false;
    document.getElementById('debug-console').innerHTML = `<span style="color: #aaaaaa;">Stopped.</span>`;
    suppressDeclarationEvaluation = true;
    editorDeclarations.setValue(tutorials[num].decl);
    editorUpdate.setValue(tutorials[num].upd);
    suppressDeclarationEvaluation = false;
    evalDeclarations();
};

function generateId() {
    return 'cube_' + Math.random().toString(36).substr(2, 9);
}

function animate() {
    requestAnimationFrame(animate);
    const dt = clock.getDelta();
    window.Time.deltaTime = dt;
    actionCooldown -= dt;

    if (isExecuting) {
        window._evalStartTime = performance.now();
        try { 
            eval(cachedUpdateCode);
        } catch (e) { 
            isExecuting = false; 
            setDebugError("Update Error: " + e.message + "<br><em>Execution stopped.</em>");
            console.error("Update Error:", e); 
        }
    }

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

        editorForwardPosition.set(0, 0, -4).applyMatrix4(camera.matrixWorld);
        editorPointer.position.set(
            Math.round(editorForwardPosition.x),
            Math.round(editorForwardPosition.y),
            Math.round(editorForwardPosition.z)
        );

        if (actionCooldown <= 0) {
            if (window.Input.GetKey(window.KeyCode.LeftControl)) {
                const id = selectedObjectColliderName || generateId();
                const c = window.qb(id, editorPointer.position.x, editorPointer.position.y, editorPointer.position.z);
                const r = document.getElementById('obj-r').value / 255;
                const g = document.getElementById('obj-g').value / 255;
                const b = document.getElementById('obj-b').value / 255;
                const a = document.getElementById('obj-a').value;
                c.material.color.setRGB(r, g, b);
                c.material.opacity = parseFloat(a);
                if (selectedObjectTexture) {
                    window.tx(id, selectedObjectTexture, "File", 6);
                }
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

    if (mapEditorMode) {
        editorPointer.visible = true;
        const placementY = Number(mapObjectYInput.value) || 0;
        editorPointer.position.y = placementY;
        const panSpeed = 20 * dt;
        if (window.Input.GetKey(window.KeyCode.W)) camera.position.z -= panSpeed;
        if (window.Input.GetKey(window.KeyCode.S)) camera.position.z += panSpeed;
        if (window.Input.GetKey(window.KeyCode.A)) camera.position.x -= panSpeed;
        if (window.Input.GetKey(window.KeyCode.D)) camera.position.x += panSpeed;

        if (actionCooldown <= 0) {
            // Arrow keys pan the camera and placement cursor together. The
            // cursor stays fixed on screen while reaching new map coordinates.
            if (window.Input.GetKey(window.KeyCode.UpArrow)) {
                camera.position.z -= 1;
                editorPointer.position.z -= 1;
                actionCooldown = 0.15;
            }
            if (window.Input.GetKey(window.KeyCode.DownArrow)) {
                camera.position.z += 1;
                editorPointer.position.z += 1;
                actionCooldown = 0.15;
            }
            if (window.Input.GetKey(window.KeyCode.LeftArrow)) {
                camera.position.x -= 1;
                editorPointer.position.x -= 1;
                actionCooldown = 0.15;
            }
            if (window.Input.GetKey(window.KeyCode.RightArrow)) {
                camera.position.x += 1;
                editorPointer.position.x += 1;
                actionCooldown = 0.15;
            }

            if (window.Input.GetKey(window.KeyCode.C)) {
                centerMapPointerOnView();
                actionCooldown = 0.2;
            }

            if (window.Input.GetKey(window.KeyCode.Z)) {
                mapObjectRotationInput.value = (Number(mapObjectRotationInput.value) || 0) - 15;
                actionCooldown = 0.15;
            }
            if (window.Input.GetKey(window.KeyCode.X)) {
                mapObjectRotationInput.value = (Number(mapObjectRotationInput.value) || 0) + 15;
                actionCooldown = 0.15;
            }

            if (window.Input.GetKey(window.KeyCode.LeftControl)) {
                if (selectedMapObject) {
                    placeMapEditorObject({
                        file: selectedMapObject,
                        x: editorPointer.position.x,
                        y: placementY,
                        z: editorPointer.position.z,
                        rotationY: Number(mapObjectRotationInput.value) || 0,
                        scale: Math.max(0.01, Number(mapObjectScaleInput.value) || 1)
                    });
                } else {
                    selectedMapObjectLabel.textContent = 'Select a JSON object first.';
                }
                actionCooldown = 0.25;
            }
            if (window.Input.GetKey(window.KeyCode.E)) {
                for (let i = cubes.length - 1; i >= 0; i--) {
                    if (cubes[i].userData.mapEditorObject &&
                        cubes[i].position.distanceTo(editorPointer.position) < 0.75) {
                        window.dl(cubes[i].name);
                        updateMapObjectCount();
                        break;
                    }
                }
                actionCooldown = 0.25;
            }
        }

        syncMapObjectPreview();
    }

    renderer.render(scene, camera);
}

const hideAllMenus = () => {
    if (document.activeElement) document.activeElement.blur();
    closeMapObjectPicker();
    mapEditorLoadToken++;
    discardPendingMapEditorObjects();
    document.querySelectorAll('.menu-panel').forEach(el => el.style.display = 'none');
    objectEditorMode = false;
    mapEditorMode = false;
    setSkyboxVisible(true);
    removeMapObjectPreview();
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
    actionCooldown = 0;
    updateMapObjectCount();
    mapEditorMode = true;
    setSkyboxVisible(false);
    createMapObjectPreview();
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

document.getElementById('btn-run').onclick = (e) => {
    if (e.target) e.target.blur(); 
    isExecuting = !isExecuting;

    if (isExecuting) {
        renderer.domElement.focus({ preventScroll: true });
    }
    
    if (listener.context.state === 'suspended') {
        listener.context.resume();
    }

    if (!isExecuting) {
        document.getElementById('debug-console').innerHTML = `<span style="color: #aaaaaa;">Stopped.</span>`;
        evalDeclarations(); 
    } else {
        clearDebug();
    }
};

editorDeclarations.on('change', () => {
    if (!isExecuting && !suppressDeclarationEvaluation) evalDeclarations();
});
editorUpdate.on('change', refreshCachedUpdateCode);

window.addEventListener('resize', () => {
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
});

animate();

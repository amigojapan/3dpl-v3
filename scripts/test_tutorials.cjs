// Verify the built-in games match their source files and load through the UI.
// Run: node scripts/test_tutorials.cjs
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const main = fs.readFileSync('main.js','utf8');
const buttons = [], names = [];
const container = {innerHTML:'',appendChild(button){buttons.push(button);}};
const debug = {innerHTML:''};
let evaluations = 0;
const context = vm.createContext({
    document:{
        activeElement:{blur(){}},
        querySelector(selector){assert.equal(selector,'.tutorial-row'); return container;},
        createElement(tag){assert.equal(tag,'button');return {attributes:{},setAttribute(k,v){this.attributes[k]=v;}};},
        getElementById(id){assert.equal(id,'debug-console');return debug;}
    },
    isExecuting:true,
    editorDeclarations:{setValue(value){this.value=value;}},
    editorUpdate:{setValue(value){this.value=value;}},
    setCurrentProgramName(name){names.push(name);},
    evalDeclarations(){evaluations++;}
});
const start=main.indexOf('const tutorials = {');
const end=main.indexOf('function generateId()',start);
assert.ok(start>=0&&end>start);
vm.runInContext(main.slice(start,end),context);
const tutorials=vm.runInContext('tutorials',context);
assert.equal(buttons.length,36);
assert.deepEqual(buttons.map(button=>button.id),Array.from({length:36},(_,i)=>'tut-'+(i+1)));
for(const [number,title,program,source='Programs/'] of [
    [33,'Car Simulator 6 with LoadMap collisions','Tutorial 33 Car Simulator'],
    [34,'Supersmash Tokyo','finalx3'],
    [35,'DUNGEON 6','DUNGEON 6'],
    [36,'Helicopter Flight Simulator 4','Helicopet Flight Simulator 4']
]) {
    const declaration=fs.readFileSync(source+program+'.declarations','utf8');
    const update=fs.readFileSync(source+program+'.update','utf8');
    assert.equal(tutorials[number].title,title);
    assert.equal(tutorials[number].decl,declaration,'Embedded declarations must match current game');
    assert.equal(tutorials[number].upd,update,'Embedded update must match current game');
    const button=buttons[number-1], before=evaluations;
    assert.equal(button.type,'button');
    assert.ok(button.attributes['aria-label'].includes(title));
    button.onclick();
    assert.equal(context.editorDeclarations.value,declaration);
    assert.ok(context.editorUpdate.value.endsWith(update));
    assert.equal(names.at(-1),title);
    assert.equal(context.isExecuting,false);
    assert.equal(evaluations,before+1,'Evaluate once after loading both program halves');
    assert.equal(vm.runInContext('suppressDeclarationEvaluation',context),false);
}
buttons[17].onclick();
assert.equal(context.editorDeclarations.value,tutorials[18].decl,'Existing tutorials remain selectable');
console.log('PASS: 36 tutorial buttons, accurate embedded game sources, accessible titles, editor loading, and existing tutorial selection.');

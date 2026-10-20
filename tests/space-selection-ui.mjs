// Dependency-free control-logic tests; the real browser is also checked manually.
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../wp-plugin/mathlin-booking/public/space-selection.js',import.meta.url),'utf8');
let checks=0;
function ok(value,message){checks++;assert.ok(value,message);}
function fixture(current='',enabled=true){
    const all=[];
    class Element {
        constructor(tag){this.tag=tag;this.children=[];this.dataset={};this.style={};this.events={};this.value='';this.checked=false;this.disabled=false;all.push(this);}
        append(...items){items.forEach(item=>this.appendChild(item));}
        appendChild(item){this.children.push(item);item.parent=this;return item;}
        insertBefore(item,before){const at=this.children.indexOf(before);this.children.splice(at<0?0:at,0,item);item.parent=this;}
        setAttribute(k,v){this[k]=v;}
        addEventListener(k,fn){(this.events[k]??=[]).push(fn);}
        dispatchEvent(event){(this.events[event.type]||[]).forEach(fn=>fn(event));}
        remove(){if(this.parent)this.parent.children=this.parent.children.filter(c=>c!==this);}
        get firstChild(){return this.children[0]||null;}
        get options(){return this.children;}
        add(option){this.appendChild(option);}
    }
    function Option(label,value){const o=new Element('option');o.textContent=label;o.value=value;return o;}
    const spaces={'Whole Park':{capacity:1000},'Bomb Hole':{parent:'Whole Park',capacity:100},Cabin:{parent:'Whole Park',capacity:100},Paddock:{parent:'Whole Park',capacity:100}};
    const select=new Element('select');select.add(new Option('Select a space',''));Object.keys(spaces).forEach(n=>select.add(new Option(n,n)));select.value=current;
    const root=new Element('div');root.dataset.config=JSON.stringify({target:'space',spaces,enabled,current});
    const document={readyState:'complete',querySelectorAll:()=>[root],getElementById:()=>select,createElement:tag=>new Element(tag),createTextNode:text=>({textContent:text})};
    class Event{constructor(type){this.type=type;}}
    vm.runInNewContext(source,{document,Option,Event});
    const inputs=all.filter(e=>e.tag==='input'),toggle=inputs.find(e=>!e.value),choice=name=>inputs.find(e=>e.value===name);
    function change(el,value){el.checked=value;el.dispatchEvent(new Event('change'));}
    function selectSingle(value){select.value=value;select.dispatchEvent(new Event('change'));}
    return{select,root,inputs,toggle,choice,change,selectSingle};
}
let f=fixture();ok(f.select.options.some(o=>o.value===''),'Initial empty option retained');
f.change(f.toggle,true);ok(f.select.options.some(o=>o.value===''),'Opening selection cannot remove placeholder');
f.change(f.choice('Bomb Hole'),true);ok(f.toggle.checked,'One area does not close the area picker');
f.change(f.choice('Cabin'),true);ok(f.select.value==='Bomb Hole + Cabin','Two areas use canonical field');
ok(!f.choice('Paddock').checked,'Unselected Paddock remains unselected');
ok(f.select.options.filter(o=>o.value==='Bomb Hole + Cabin').length===1,'Only one combination option');
f.change(f.toggle,false);ok(f.select.value==='','Turning off multiple areas leaves an explicit empty choice');
ok(f.select.options.some(o=>o.value===''),'Empty placeholder survives deselection');
ok(!f.select.options.some(o=>o.value.includes(' + ')),'Stale combination removed');
ok(!f.choice('Cabin').checked&&!f.choice('Bomb Hole').checked,'Hidden choices cleared');
f.change(f.toggle,true);f.change(f.choice('Cabin'),true);f.change(f.choice('Bomb Hole'),true);f.selectSingle('Whole Park');
ok(f.select.value==='Whole Park'&&!f.toggle.checked,'Whole Park remains a single exclusive choice');
ok(!f.choice('Cabin').checked,'Whole Park clears hidden member checkboxes');
ok(!f.select.options.some(o=>o.value.includes(' + ')),'Whole Park leaves no stale combination');
f=fixture('Bomb Hole + Cabin');
ok(f.toggle.checked&&f.choice('Cabin').checked&&f.choice('Bomb Hole').checked,'Existing selection is restored');
ok(f.select.options.filter(o=>o.value==='Bomb Hole + Cabin').length===1,'Existing edit form does not duplicate its combination');
f=fixture('Bomb Hole + Cabin',false);ok(f.select.value==='Bomb Hole + Cabin','Disabling feature preserves stored selection');
ok(f.inputs.length===0,'Disabled feature exposes no new multi-area controls');
console.log(`SPACE_SELECTION_UI_OK (${checks} checks)`);

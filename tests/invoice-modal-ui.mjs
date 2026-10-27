import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';
import vm from 'node:vm';
const source=readFileSync(new URL('../wp-plugin/mathlin-booking/admin/admin.js',import.meta.url),'utf8');
const start=source.indexOf('(function($)',source.indexOf('// ── Invoice document modal'));
const end=source.indexOf('})(jQuery);',start)+'})(jQuery);'.length;
assert.ok(start>0&&end>start);
const handlers=[],requests=[],doc={activeElement:null};let visible=false;
const close={focus(){doc.activeElement=this;},getAttribute(){return null;}},download={focus(){doc.activeElement=this;},getAttribute(){return null;}},footer={focus(){doc.activeElement=this;},getAttribute(){return null;}};
const body={html:'',attrs:{}},title={},controls=[close,download,footer],modalNode={contains(el){return controls.includes(el);}};
function collection(items){const c={length:items.length};items.forEach((v,i)=>c[i]=v);c.first=()=>collection(items.slice(0,1));c.last=()=>collection(items.slice(-1));c.filter=selector=>collection(typeof selector==='function'?items.filter(x=>selector.call(x)):items);c.focus=()=>{items[0]?.focus();return c;};c.html=value=>{if(value!==undefined)items[0].html=value;return c;};c.attr=(k,v)=>{items[0].attrs??={};items[0].attrs[k]=v;return c;};c.text=value=>{items[0].text=value;return c;};c.data=k=>items[0].data?.[k];c.is=()=>visible||items[0]!==modalNode;c.show=()=>{visible=true;return c;};c.hide=()=>{visible=false;return c;};c.find=selector=>selector==='.mbs-modal__body'?collection([body]):selector==='.mbs-modal__title'?collection([title]):selector==='.mbs-modal__download'?collection([download]):selector==='.mbs-modal__close'?collection([close]):collection(controls);return c;}
function $(target){if(target===doc)return{on(event,...args){handlers.push({event,selector:typeof args[0]==='string'?args[0]:null,fn:args.at(-1)});}};return target==='#mbs-invoice-modal'?collection([modalNode]):collection([target]);}
$.ajax=request=>requests.push(request);
vm.runInNewContext(source.slice(start,end),{jQuery:$,document:doc,MBS_Admin:{ajax_url:'/ajax',doc_nonce:'synthetic'}});
const click=handlers.find(h=>h.selector==='.mbs-view-invoice-btn').fn,key=handlers.find(h=>h.event==='keydown').fn;
function trigger(id){const button={data:{'document-id':id,'invoice-ref':'INV-'+id},focus(){doc.activeElement=this;}};click.call(button,{preventDefault(){}});return button;}
const opener=trigger(1);trigger(2);requests[0].success({success:true,data:{html:'OLD'}});assert.notEqual(body.html,'OLD');requests[1].success({success:true,data:{html:'CURRENT'}});assert.equal(body.html,'CURRENT');assert.equal(body.attrs['aria-busy'],'false');
let prevented=false;doc.activeElement=footer;key({key:'Tab',shiftKey:false,preventDefault(){prevented=true;}});assert.equal(doc.activeElement,close);assert.ok(prevented);
key({key:'Tab',shiftKey:true,preventDefault(){}});assert.equal(doc.activeElement,footer);
key({key:'Escape',preventDefault(){}});assert.equal(visible,false);assert.equal(doc.activeElement.data['document-id'],2);requests[1].error();assert.equal(body.html,'');
trigger(3);requests[0].error();assert.equal(body.attrs['aria-busy'],'true');assert.ok(body.html.includes('Loading invoice'));assert.notEqual(doc.activeElement,opener);
console.log('INVOICE_MODAL_UI_OK: stale response rejection, loading state, Tab/Shift-Tab containment, Escape and focus return');

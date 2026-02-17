
function applyAnalyticsCode(code){
  if(!code || window.__siteAnalyticsApplied) return;
  window.__siteAnalyticsApplied = true;
  const host = document.createElement('div');
  host.style.display = 'none';
  host.innerHTML = String(code);
  const scripts = host.querySelectorAll('script');
  scripts.forEach(oldScript => {
    const s = document.createElement('script');
    for(const attr of oldScript.attributes) s.setAttribute(attr.name, attr.value);
    if(oldScript.textContent) s.textContent = oldScript.textContent;
    document.body.appendChild(s);
  });
}

async function loadSiteSettings(){
  try{
    const r = await fetch('/api/site_public.php');
    const j = await r.json();
    if(!j.ok) return;
    if(document.getElementById('brandName')) document.getElementById('brandName').textContent = j.settings.siteName || document.getElementById('brandName').textContent;
    if(document.getElementById('brandSub')) document.getElementById('brandSub').textContent = j.settings.siteSub || document.getElementById('brandSub').textContent;
    const icp = document.getElementById('icpText'); if(icp) icp.textContent = j.settings.icp || icp.textContent;
    applyAnalyticsCode(j.settings.analyticsCode || '');
  }catch{}
}
const SITE = { name:"心象研究所", sub:"测评 · 性格 · 关系 · 职业", programBridge:true };

const $ = (sel, el=document) => el.querySelector(sel);
const $$ = (sel, el=document) => Array.from(el.querySelectorAll(sel));

function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}


function bridgeToMiniProgram(eventName, payload={}){
  if(!SITE.programBridge) return;
  const msg = { source:"psy-site", event:eventName, payload, ts:Date.now() };
  try{ window.parent?.postMessage?.(msg, "*"); }catch{}
  try{ window.wx?.miniProgram?.postMessage?.({ data: msg }); }catch{}
}

function setupBubbles(){
  const host = $("#bubbles");
  const count = 16;
  const frag = document.createDocumentFragment();
  for(let i=0;i<count;i++){
    const b = document.createElement("div");
    b.className = "bubble";
    const size = 22 + Math.random()*70;
    b.style.width = size.toFixed(1)+"px";
    b.style.height = size.toFixed(1)+"px";
    b.style.left = (Math.random()*100).toFixed(4)+"%";
    b.style.animationDelay = (Math.random()*5).toFixed(3)+"s";
    b.style.animationDuration = (10+Math.random()*10).toFixed(3)+"s";
    frag.appendChild(b);
  }
  host.appendChild(frag);
}

function viewPath(id){ return `/view/${encodeURIComponent(id)}/`; }
function isAuthed(){ return !!(window.Auth?.getAuth?.()?.ok); }

function setBrand(){
  document.title = SITE.name;
  $("#brandName").textContent = SITE.name;
  const brand2 = $("#brandName2"); if(brand2) brand2.textContent = SITE.name;
  $("#brandSub").textContent = SITE.sub;
  const y = $("#year"); if(y) y.textContent = new Date().getFullYear();
}

const CATEGORIES = ["全部", ...Array.from(new Set(TESTS.map(t => t.category)))];
let state = { q:"", cat:"全部" };

function renderChips(active){
  const chips = $("#chips");
  chips.innerHTML = CATEGORIES.map(c => {
    const cls = c === active ? "chip active" : "chip";
    return `<button class="${cls}" type="button" data-cat="${escapeHtml(c)}"
      style="border:1px solid rgba(0,0,0,.08);background:rgba(255,255,255,.75);padding:9px 12px;border-radius:999px;cursor:pointer;transition:.16s ease;font-weight:900;font-size:13px;color:#333">${escapeHtml(c)}</button>`;
  }).join("");
  chips.addEventListener("click", (e) => {
    const btn = e.target.closest("button[data-cat]");
    if(!btn) return;
    setCategory(btn.dataset.cat);
  });
}

function matches(t){
  const q = state.q.trim().toLowerCase();
  const okQ = !q || t.title.toLowerCase().includes(q) || (t.tags||[]).some(x => String(x).toLowerCase().includes(q)) || t.id.toLowerCase().includes(q);
  const okC = state.cat === "全部" || t.category === state.cat;
  return okQ && okC;
}

function setCategory(cat){
  state.cat = cat;
  $$("#chips button[data-cat]").forEach(x => x.style.background = (x.dataset.cat === cat ? "linear-gradient(135deg,var(--primary),var(--accent))" : "rgba(255,255,255,.75)"));
  $$("#chips button[data-cat]").forEach(x => x.style.color = (x.dataset.cat === cat ? "#fff" : "#333"));
  render();
}

function render(){
  const list = TESTS.filter(matches);
  $("#meta").textContent = `显示 ${list.length} / ${TESTS.length}`;
  const grid = $("#grid");

  if(list.length === 0){
    grid.innerHTML = `<div style="background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid rgba(0,0,0,.06);padding:14px;grid-column:1/-1"><div class="small">没有找到匹配的测试。试试换个关键词或切换分类。</div></div>`;
    return;
  }

  grid.innerHTML = list.map((t, idx) => {
    const href = viewPath(t.id);
    const tags = (t.tags||[]).slice(0,4).map(x => `<span style="font-size:11px;color:#4b4b4b;background:rgba(124,131,253,.12);border:1px solid rgba(124,131,253,.18);padding:4px 8px;border-radius:999px">${escapeHtml(x)}</span>`).join("");
    return `<article style="background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid rgba(0,0,0,.06);padding:14px;transition:.18s ease;display:flex;flex-direction:column"
      onmouseenter="this.style.transform='translateY(-6px)';this.style.boxShadow='var(--shadow2)'" onmouseleave="this.style.transform='';this.style.boxShadow='var(--shadow)'">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px">
        <div style="display:flex;gap:10px;min-width:0">
          <div style="width:34px;height:34px;border-radius:12px;display:grid;place-items:center;font-weight:900;color:#fff;background:linear-gradient(135deg,var(--primary),var(--accent));flex:0 0 auto">${idx+1}</div>
          <div style="min-width:0">
            <h3 style="margin:0;font-size:15px;line-height:1.4;font-weight:900">${escapeHtml(t.title)}</h3>
            <p style="margin:6px 0 0;color:var(--muted);font-size:12.5px;line-height:1.55;min-height:38px">${escapeHtml(t.intro || "")}</p>
          </div>
        </div>
      </div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:10px">${tags}</div>
      <div style="margin-top:auto;padding-top:10px;display:flex;gap:10px;align-items:center;justify-content:space-between">
        ${isAuthed() ? `<span class="link" style="opacity:.8">已激活</span>` : `<a class="link" href="/code?redirect=${encodeURIComponent(href)}">解锁</a>`}
        <a class="go" href="${escapeHtml(href)}">${isAuthed() ? "继续" : "进入"}</a>
      </div>
    </article>`;
  }).join("");
}

function renderTopAuth(){
  const navBtn = document.querySelector(".nav-link.primary");
  if(!navBtn) return;
  if(isAuthed()){
    navBtn.textContent = "已激活";
    navBtn.href = "/code";
    const gift = document.getElementById("heroActivateBadge");
    if(gift) { gift.textContent = "✅ 权限已激活"; gift.href = "/code"; }
  }
}

function setupRandomPick(){
  $("#randomPick").addEventListener("click", () => {
    const list = TESTS.filter(matches);
    const pick = list[Math.floor(Math.random()*list.length)];
    if(!pick) return;
    bridgeToMiniProgram("open_test", { id: pick.id, from: "random" });
    location.href = viewPath(pick.id);
  });
}

(function init(){
  localStorage.setItem("psy_last_path", location.pathname + location.search + location.hash);
  setBrand();
  loadSiteSettings();
  setupBubbles();
  renderTopAuth();
  renderChips(state.cat);
  setupRandomPick();

  const q = $("#q");
  q.addEventListener("input", () => { state.q = q.value; render(); });
  render();
})();

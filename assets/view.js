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


let _freePreviewQuestions = 3; // default, will be updated from site settings

async function loadSiteSettings(){
  try{
    const r = await fetch('/api/site_public.php');
    const j = await r.json();
    if(!j.ok) return;
    if(document.getElementById('brandName')) document.getElementById('brandName').textContent = j.settings.siteName || document.getElementById('brandName').textContent;
    if(document.getElementById('brandSub')) document.getElementById('brandSub').textContent = j.settings.siteSub || document.getElementById('brandSub').textContent;
    const icp = document.getElementById('icpText'); if(icp) icp.textContent = j.settings.icp || icp.textContent;
    applyAnalyticsCode(j.settings.analyticsCode || '');
    if(typeof j.settings.freePreviewQuestions === 'number') _freePreviewQuestions = j.settings.freePreviewQuestions;
  }catch{}
}
const SITE = { name:"心象研究所", sub:"测评 · 性格 · 关系 · 职业", miniProgramReserved:true };

const $ = (sel, el=document) => el.querySelector(sel);

function escapeHtml(str){
  return String(str).replace(/[&<>"']/g, s => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[s]));
}


function renderInfoPanels(test){
  const c = test.content || {};
  const howto = (c.howto||[]).map(x => `<li>${escapeHtml(x)}</li>`).join("");
  const about = (c.about||[]).map(x => `<li>${escapeHtml(x)}</li>`).join("");
  const defaultHowtoByCat = {"情绪量表":"<li>按最近两周状态作答，更能反映当前压力与情绪变化。</li><li>不必追求“好答案”，真实作答更有意义。</li><li>若中途离开，返回后可继续。</li>","人格性格":"<li>基于长期稳定行为倾向作答，不只看某一天状态。</li><li>优先选择“更像你平时”的选项。</li><li>结果用于自我理解，不用于给自己贴标签。</li>","恋爱关系":"<li>建议结合最近一段关系互动体验作答。</li><li>题目没有对错，重点是看见自己的沟通模式。</li><li>可在结果页查看建议并二次复测对比。</li>","职业天赋":"<li>尽量以真实职业/学习场景作答。</li><li>优先选择长期稳定偏好，而非短期情绪。</li><li>可与过往经历对照理解结果。</li>","趣味外貌":"<li>请轻松作答，结果偏娱乐与自我观察。</li><li>无需追求高分或完美结果。</li><li>建议理性看待外貌相关结论。</li>","自我探索":"<li>请基于最近一段时间的真实状态作答。</li><li>把它当作整理自我的一次机会。</li><li>支持复测对比，观察变化趋势。</li>","认知能力":"<li>请在相对安静、网络稳定的环境完成。</li><li>看到题目后优先凭第一反应作答，避免过度纠结。</li><li>遇到不确定题可先标记，再继续后续题目。</li>"};
  const defaultAboutByCat = {"情绪量表":"<li>本结果不替代临床诊断或治疗建议。</li><li>若你持续感到痛苦，请及时联系专业机构。</li>","职业天赋":"<li>结果适合用于职业方向讨论，不是唯一决策依据。</li><li>可结合兴趣、能力与现实条件综合判断。</li>","自我探索":"<li>结果用于自我觉察与成长反思。</li><li>建议隔一段时间复测观察变化趋势。</li>","认知能力":"<li>此类结果更适合用于阶段性表现回顾，不代表固定智力水平。</li><li>建议结合睡眠、压力与注意力状态综合理解结果。</li>"};
  const defaultHowtoById = {
    gad7:"<li>围绕近两周的紧张与担忧体验作答。</li><li>若近期有重大生活事件，请在结果解读时一并考虑。</li>",
    phq9:"<li>按过去两周的真实状态作答，避免代替“理想中的自己”作答。</li><li>如出现持续低落或无助感，建议尽快寻求专业支持。</li>",
    sleep:"<li>请按最近一周作息节律与入睡体验作答。</li><li>建议在相对固定的时间段完成，以便后续复测对比。</li>",
    mbti:"<li>请依据长期偏好作答，而不是某次社交状态。</li><li>遇到难选项时，优先选择“更常见”的反应方式。</li>",
    holland:"<li>请按你“愿意长期投入”的活动偏好作答。</li><li>结果可用于职业方向探索，不等于岗位匹配结论。</li>"
  };
  const defaultAboutById = {
    dark:"<li>该结果用于人格阴影特质观察，不用于价值评判或贴标签。</li><li>建议结合现实行为与关系反馈综合理解。</li>",
    yanzhi:"<li>当前为图像分析占位流程页，结果偏体验展示。</li><li>请勿将外貌类输出作为自我价值判断依据。</li>",
    scl90:"<li>SCL-90 适合做阶段性筛查，不替代临床诊断。</li><li>如多个维度长期偏高，请联系专业人员进一步评估。</li>"
  };

  let typesHtml = "";
  if(test.mode === "animal"){
    // Check rule.types first (new location), then content.types (old location) for backwards compatibility
    const types = (test.rule && test.rule.types) ? test.rule.types :
                  (c.types && Array.isArray(c.types)) ? c.types : [];
    if(types.length > 0){
      typesHtml = `
        <details class="details">
          <summary>动物类型预览 <span class="small">（点击展开）</span></summary>
          <div class="details-b">
            <div class="type-grid">
              ${types.map(t => `
                <div class="type-card">
                  <div class="h"><div class="name">${escapeHtml(t.icon||"")} ${escapeHtml(t.name||"")}</div></div>
                  <div class="tag">${escapeHtml(t.tagline||"")}</div>
                </div>
              `).join("")}
            </div>
          </div>
        </details>
      `;
    }
  }

  return `
    <details class="details" open>
      <summary>作答方式 <span class="small">（可折叠）</span></summary>
      <div class="details-b"><ol style="margin:8px 0 0;padding-left:18px">${howto || defaultHowtoById[test.id] || defaultHowtoByCat[test.category] || "<li>请基于近两周的真实体验作答。</li><li>无需追求“正确答案”，越真实越有价值。</li><li>若中途离开可自动保存，返回后继续。</li>"}</ol></div>
    </details>
    <details class="details">
      <summary>说明与提醒 <span class="small">（点击展开）</span></summary>
      <div class="details-b"><ul style="margin:8px 0 0;padding-left:18px">${about || defaultAboutById[test.id] || defaultAboutByCat[test.category] || "<li>结果用于自我觉察，不替代医学诊断。</li><li>若你正处在明显情绪困扰中，请及时寻求专业帮助。</li>"}</ul></div>
    </details>
    ${typesHtml}
  `;
}

function clamp01(x){ return Math.max(0, Math.min(1, x)); }
function pct(x){ return `${Math.round(clamp01(x)*100)}%`; }

function setupBubbles(){
  const host = document.getElementById("bubbles");
  const count = 14;
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

function toast(msg){
  const el = $("#toast");
  el.textContent = msg;
  el.style.opacity = "1";
  clearTimeout(toast._t);
  toast._t = setTimeout(()=> el.style.opacity="0", 1600);
}

function qs(){
  const p = new URLSearchParams(location.search);
  return Object.fromEntries(p.entries());
}
function getTestId(){ return (qs().id || "").trim(); }
function findTest(id){ return TESTS.find(t => t.id === id) || null; }

function keyFor(id, suffix){ return `psy_test_${id}_${suffix}`; }
function loadJSON(key, fallback){
  try{ const raw = localStorage.getItem(key); return raw ? JSON.parse(raw) : fallback; }catch{ return fallback; }
}
function saveJSON(key, value){ localStorage.setItem(key, JSON.stringify(value)); }

function viewPath(id){ return `/view/${encodeURIComponent(id)}/`; }
function buildRedirectParam(){ return encodeURIComponent(location.pathname + location.search + location.hash); }
function formatDate(ts){ if(!ts) return "-"; const d=new Date(ts*1000); const p=n=>String(n).padStart(2,"0"); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; }
function getAuthState(){ return window.Auth?.getAuth?.() || { ok:false }; }
function isAuthed(){ return !!getAuthState().ok; }
function bridgeToMiniProgram(eventName, payload={}){
  if(!SITE.miniProgramReserved) return;
  const msg = { source:"psy-site", event:eventName, payload, ts:Date.now() };
  try{ window.parent?.postMessage?.(msg, "*"); }catch{}
  try{ window.wx?.miniProgram?.postMessage?.({ data: msg }); }catch{}
}

function authUI(){
  const a = getAuthState();
  const btn = $("#authBtn");
  const status = $("#authStatus");
  if(a && a.ok){
    btn.textContent = "权限有效";
    btn.href = "/code?from=view";
    status.innerHTML = `权限有效至 <span class="kbd">${formatDate(a.exp)}</span>`;
  }else{
    btn.textContent = "激活权限";
    btn.href = `/code?redirect=${buildRedirectParam()}`;
    status.textContent = "未激活（部分功能将被锁定）";
  }
}

function testUiHint(test){
  if(test.id === "mbti" || test.id === "mbti16") return "🧭 人格倾向图谱";
  if(test.id === "scl90") return "🩺 情绪维度总览";
  if(test.id === "yanzhi") return "🖼️ 图片分析占位流程";
  return "✨ 多维心理测评";
}

function setBrand(){
  $("#brandName").textContent = SITE.name;
  $("#brandSub").textContent = SITE.sub;
}

function renderMeta(test){
  document.title = test.title;
  $("#title").textContent = test.title;
  $("#intro").textContent = test.intro;

  $("#breadcrumb").innerHTML = `
    <a href="/">全部测试</a>
    <span aria-hidden="true">/</span>
    <span>${escapeHtml(test.category)}</span>
    <span aria-hidden="true">/</span>
    <span>项目详情</span>
  `;

  const chips = [];
  const specialTheme = { mbti:"🧠 认知维度视角", mbti16:"🧭 心智阶段视角", dark:"🌓 暗色人格视角", yanzhi:"📸 图像分析流程", holland:"🧩 职业兴趣六型", scl90:"🧾 九维症状雷达", olson:"💞 关系结构剖面", wuxing:"🌿 东方人格映射", rpi:"🔍 风险偏好轮廓" };
  if(specialTheme[test.id]) chips.push(`<span class="info-chip">${specialTheme[test.id]}</span>`);
  chips.push(`<span class="info-chip">${escapeHtml(testUiHint(test))}</span>`);
  if(test.mode === "scl90" && Array.isArray(test.variants)){
    chips.push(`<span class="info-chip">完整版 90 题 / 约 25-35 分钟</span>`);
    chips.push(`<span class="info-chip">速测版 30 题 / 约 8-12 分钟</span>`);
  }else{
    chips.push(`<span class="info-chip">预计 ${test.estimated} 分钟</span>`);
    if(test.questions && test.questions.length) chips.push(`<span class="info-chip">${test.questions.length} 题</span>`);
  }
  (test.tags||[]).slice(0,4).forEach(t => chips.push(`<span class="info-chip">${escapeHtml(t)}</span>`));
  $("#infoChips").innerHTML = chips.join("");
}

function renderHistory(test){
  const history = loadJSON(keyFor(test.id,"history"), []);
  const host = $("#history");
  if(!history.length){
    host.innerHTML = `<div class="small">暂无历史记录。完成一次测评后会自动记录。</div>`;
    return;
  }
  host.innerHTML = history.slice(0,8).map((h, i) => `
    <div class="h-item">
      <div class="t">${escapeHtml(h.title || "结果")}</div>
      <div class="m">${escapeHtml(h.time || "")}</div>
      <div class="a">
        <a class="link" href="#" data-copy="${escapeHtml(h.share || "")}">复制分享</a>
        <a class="go" href="#" data-view="${i}">查看</a>
      </div>
    </div>
  `).join("");

  host.addEventListener("click", (e) => {
    const viewBtn = e.target.closest("a[data-view]");
    if(viewBtn){
      e.preventDefault();
      const idx = Number(viewBtn.dataset.view);
      const h = history[idx];
      if(h) showResult(test, h.result, true);
    }
  });

  document.addEventListener("click", async (e) => {
    const a = e.target.closest("a[data-copy]");
    if(!a) return;
    e.preventDefault();
    const txt = a.dataset.copy || location.href;
    try{ await navigator.clipboard.writeText(txt); toast("已复制"); }catch{ toast("复制失败"); }
  });
}

function clearLocalFor(test){
  ["progress","answers","history"].forEach(s => localStorage.removeItem(keyFor(test.id, s)));
  toast("已清除本页缓存");
  renderHistory(test);
  renderHome(test);
}

function renderLocked(test){
  const panel = $("#mainPanel");
  panel.classList.add("locked");
  $("#panelTitle").textContent = "需要激活";
  $("#progressText").textContent = "";

  $("#panelBody").innerHTML = `
    <div class="lock-cta">
      <div class="cta-card warn">
        <h4>⚠️ 当前未激活，暂时无法开始答题</h4>
        <p>完成激活后即可解锁完整题库与结果卡。激活成功会自动返回当前测试继续。</p>
        <div class="cta-steps"><div>1）点击“去激活”并输入激活码</div><div>2）激活成功后自动跳回本页</div><div>3）可从中断位置继续作答</div></div>
        <div class="row">
          <a class="btn btn-primary" href="/code?redirect=${buildRedirectParam()}">去激活</a>
          <a class="btn btn-ghost" href="/">先回测评库看看</a>
        </div>
        <div class="small" style="margin-top:10px">你可以先阅读简介；激活成功会自动返回当前测试继续。</div>
      </div>
    </div>
  `;
  $("#panelActions").innerHTML = "";
}

function renderPaywall(test){
  const panel = $("#mainPanel");
  panel.classList.add("locked");
  $("#panelTitle").textContent = "解锁完整测评";
  $("#progressText").textContent = "";

  $("#panelBody").innerHTML = `
    <div class="lock-cta">
      <div class="cta-card warn" style="border-color:var(--primary)">
        <h4>🔒 免费预览已结束</h4>
        <p>你已体验了前 ${_freePreviewQuestions} 道题目，想了解完整结果吗？</p>
        <p>激活后即可完成全部题目并获得专属结果解读，已作答的进度会自动保留。</p>
        <div class="cta-steps">
          <div>1）点击"去激活"获取激活码</div>
          <div>2）激活成功后自动返回本页</div>
          <div>3）从当前进度继续作答，无需重新开始</div>
        </div>
        <div class="row">
          <a class="btn btn-primary" href="/code?redirect=${buildRedirectParam()}">去激活</a>
          <a class="btn btn-ghost" href="/">先回测评库看看</a>
        </div>
        <div class="small" style="margin-top:10px">激活成功后将自动返回当前测试继续答题。</div>
      </div>
    </div>
  `;
  $("#panelActions").innerHTML = "";
}

function renderHome(test){
  const panel = $("#mainPanel");
  panel.classList.remove("locked");

  $("#panelTitle").textContent = "开始";
  $("#progressText").textContent = "";

  const progressKey = keyFor(test.id, "progress");
  const answersKey = keyFor(test.id, "answers");
  const prog = loadJSON(progressKey, null);
  const ans = loadJSON(answersKey, null);
  const resume = prog && typeof prog.index === "number" && Array.isArray(ans) && ans.length;

  const body = $("#panelBody");

  // SCL-90: variant choice (full/quick)
  let variantId = (prog && prog.variantId) ? prog.variantId : (test.variants && test.variants[0] ? test.variants[0].id : "default");

  if(test.mode === "scl90" && Array.isArray(test.variants)){
    const vHtml = test.variants.map(v => `
      <label class="option" style="align-items:center">
        <input type="radio" name="variant" value="${escapeHtml(v.id)}" ${v.id===variantId?"checked":""}/>
        <div>
          <div class="ot">${escapeHtml(v.name)}</div>
          <div class="od">${escapeHtml(v.desc || "")}</div>
        </div>
      </label>
    `).join("");
    body.innerHTML = `
      <div class="question">
        <div class="q-top">
          <div class="q-num">${escapeHtml(test.ui?.icon || "🧠")} ${escapeHtml(test.ui?.badge || "")}</div>
          <div class="small">支持中断继续</div>
        </div>
        <p class="q-text">选择答题模式</p>
        <div class="options">${vHtml}</div>
        <div style="margin-top:12px">${renderInfoPanels(test)}</div>
      </div>
    `;
    $("#panelActions").innerHTML = `
      ${resume ? `<button class="btn btn-primary" id="resumeBtn" type="button">继续上次</button>` : ``}
      <button class="btn btn-primary" id="startBtn" type="button">${resume ? "重新开始" : "开始测评"}</button>
      <a class="btn btn-ghost" href="/code?redirect=${buildRedirectParam()}">管理权限</a>
    `;

    body.addEventListener("change", (e) => {
      const r = e.target.closest("input[type=radio][name=variant]");
      if(!r) return;
      variantId = r.value;
    });

    $("#startBtn").addEventListener("click", () => startTest(test, false, variantId));
    const rBtn = $("#resumeBtn");
    if(rBtn) rBtn.addEventListener("click", () => startTest(test, true, variantId));
    
    setupDetailsToggle();
    return;
  }

  if(test.mode === "upload_placeholder"){
    body.innerHTML = `
      <div class="question">
        <div class="q-top">
          <div class="q-num">图片分析</div>
          <div class="small">隐私优先</div>
        </div>
        <p class="q-text">选择一张图片（本地预览）</p>
        <div class="small" style="margin-top:8px">
          默认不会自动上传到服务器；后续接入分析接口后，可在这里增加上传与报告。
        </div>
        <div style="margin-top:12px;display:flex;gap:10px;flex-wrap:wrap;align-items:center">
          <input type="file" id="faceFile" accept="image/*" />
          <a class="btn btn-ghost" href="/cooperate">合作 / 代搭建</a>
        </div>
        <div id="preview" style="margin-top:12px"></div>
      </div>
    `;
    $("#panelActions").innerHTML = `
      <a class="btn btn-primary" href="/cooperate">接入分析服务</a>
      <a class="btn btn-ghost" href="/">回到列表</a>
    `;
    const file = $("#faceFile");
    const preview = $("#preview");
    file?.addEventListener("change", () => {
      const f = file.files && file.files[0];
      if(!f) return;
      const url = URL.createObjectURL(f);
      preview.innerHTML = `<img src="${url}" alt="预览" style="max-width:100%;border-radius:14px;border:1px solid rgba(0,0,0,.08)" />`;
    });
    return;
  }

  // Default home
  body.innerHTML = `
    <div class="question">
      <p class="q-text">准备开始 ${escapeHtml(test.title)}</p>
      <div class="small" style="margin-top:8px">${escapeHtml(test.intro || "")}</div>
      <div class="result-badges">
        <span class="result-badge">自动保存进度</span>
        <span class="result-badge">生成结果卡</span>
        <span class="result-badge">本地历史</span>
      </div>
      <div style="margin-top:12px">${renderInfoPanels(test)}</div>
    </div>
  `;

  $("#panelActions").innerHTML = `
    ${resume ? `<button class="btn btn-primary" id="resumeBtn" type="button">继续上次</button>` : ``}
    <button class="btn btn-primary" id="startBtn" type="button">${resume ? "重新开始" : "开始测评"}</button>
    <a class="btn btn-ghost" href="/code?redirect=${buildRedirectParam()}">管理权限</a>
  `;

  $("#startBtn").addEventListener("click", () => startTest(test, false, null));
  const r = $("#resumeBtn");
  if(r) r.addEventListener("click", () => startTest(test, true, null));
  
  setupDetailsToggle();
}

function startTest(test, resume, variantId){
  const progressKey = keyFor(test.id, "progress");
  const answersKey = keyFor(test.id, "answers");

  // pick questions (variant)
  let questions = test.questions || [];
  let factorMap = null;

  if(test.mode === "scl90" && Array.isArray(test.variants)){
    const v = test.variants.find(x => x.id === variantId) || test.variants[0];
    questions = v.questions;
    factorMap = v.factorMap;
    variantId = v.id;
  }

  let index = 0;
  let answers = new Array(questions.length).fill(null);

  if(resume){
    const prog = loadJSON(progressKey, null);
    const ans = loadJSON(answersKey, null);

    // If variant changed, do not resume old answers
    if(prog && prog.variantId && variantId && prog.variantId !== variantId){
      resume = false;
    }else{
      if(prog && typeof prog.index === "number") index = Math.max(0, Math.min(questions.length-1, prog.index));
      if(Array.isArray(ans)) answers = ans.slice(0, questions.length);
    }
  }

  if(!resume){
    localStorage.removeItem(progressKey);
    localStorage.removeItem(answersKey);
  }

  function save(){
    saveJSON(progressKey, { index, updatedAt: Date.now(), variantId: variantId || null });
    saveJSON(answersKey, answers);
  }

  function showProgressOverview(){
    const answered = answers.filter(a => a !== null).length;
    const unanswered = answers.filter(a => a === null).length;
    const answerStatus = answers.map((a, i) => {
      const status = a !== null ? "✓" : "○";
      const cls = a !== null ? "answered" : "unanswered";
      return `<button class="progress-item ${cls}" data-jump="${i}" type="button">${i+1} ${status}</button>`;
    }).join("");

    $("#panelTitle").textContent = "答题进度";
    $("#progressText").textContent = `已答 ${answered}/${total} 题`;

    $("#panelBody").innerHTML = `
      <div class="question">
        <div class="q-top">
          <div class="q-num">进度总览</div>
          <div class="small">点击题号可跳转</div>
        </div>
        <div style="margin-top:12px">
          <div class="result-badges">
            <span class="result-badge">已完成：${answered} 题</span>
            <span class="result-badge">未作答：${unanswered} 题</span>
          </div>
        </div>
        <div style="margin-top:16px;display:grid;grid-template-columns:repeat(auto-fill,minmax(50px,1fr));gap:8px">
          ${answerStatus}
        </div>
        <div class="small" style="margin-top:16px;color:var(--muted)">
          ✓ 表示已作答，○ 表示未作答。可点击跳转到之前的题目。
        </div>
      </div>
    `;

    $("#panelActions").innerHTML = `
      <button class="btn btn-primary" id="backToQuestion" type="button">继续答题</button>
    `;

    $("#backToQuestion").addEventListener("click", () => {
      renderQ();
    });

    document.querySelectorAll("[data-jump]").forEach(btn => {
      btn.addEventListener("click", () => {
        const targetIndex = Number(btn.dataset.jump);
        // Only allow jumping backward to maintain answer flow integrity
        if(targetIndex <= index){
          index = targetIndex;
          save();
          renderQ();
        } else {
          toast("请按顺序作答");
        }
      });
    });
  }

  function renderQ(){
    $("#panelTitle").textContent = "答题中";
    const total = questions.length;
    $("#progressText").textContent = `${index+1}/${total}`;

    const pctv = Math.round(((index)/Math.max(1,total-1))*100);
    const q = questions[index];

    // Scroll to question panel when starting or navigating (delay allows DOM update)
    const SCROLL_DELAY_MS = 100;
    setTimeout(() => {
      const panel = $("#mainPanel");
      if(panel) panel.scrollIntoView({ behavior: "smooth", block: "start" });
    }, SCROLL_DELAY_MS);

    const opts = q.opts.map((o, oi) => {
      const checked = answers[index] === oi ? "checked" : "";
      const desc = o.d ? `<div class="od">${escapeHtml(o.d)}</div>` : "";
      return `
        <label class="option">
          <input type="radio" name="opt" value="${oi}" ${checked} />
          <div>
            <div class="ot">${escapeHtml(o.t)}</div>
            ${desc}
          </div>
        </label>
      `;
    }).join("");

    $("#panelBody").innerHTML = `
      <div class="progress" aria-label="进度条"><div style="width:${pctv}%"></div></div>
      <div style="height:10px"></div>
      <div class="question">
        <div class="q-top">
          <div class="q-num">第 ${index+1} 题</div>
          <div class="small">可随时退出，进度会自动保存</div>
        </div>
        <p class="q-text">${escapeHtml(q.q)}</p>
        <div class="options">${opts}</div>
      </div>
    `;

    $("#panelBody").addEventListener("change", (e) => {
      const r = e.target.closest("input[type=radio][name=opt]");
      if(!r) return;
      answers[index] = Number(r.value);
      save();
    }, { once:true });

    $("#panelActions").innerHTML = `
      <button class="btn btn-ghost" id="prevBtn" type="button">上一题</button>
      <button class="btn btn-primary" id="nextBtn" type="button">${index === total-1 ? "提交" : "下一题"}</button>
      <button class="btn btn-ghost" id="progressBtn" type="button" style="margin-left:auto">查看进度</button>
    `;
    $("#prevBtn").disabled = index === 0;

    $("#prevBtn").addEventListener("click", () => {
      index = Math.max(0, index-1);
      save();
      renderQ();
    });

    $("#progressBtn").addEventListener("click", () => {
      showProgressOverview();
    });

    $("#nextBtn").addEventListener("click", () => {
      if(answers[index] === null){ toast("请先选择一个选项"); return; }
      // 先测试后引导购买：免费预览题数限制
      if(!isAuthed() && _freePreviewQuestions > 0 && index + 1 >= _freePreviewQuestions){
        renderPaywall(test);
        return;
      }
      if(index === total-1){
        if(!isAuthed()){ renderPaywall(test); return; }
        // Check if all questions are answered before allowing submission
        const unansweredCount = answers.filter(a => a === null).length;
        if(unansweredCount > 0){
          toast(`还有 ${unansweredCount} 题未作答，请完成所有题目后再提交`);
          return;
        }
        submit(test, answers, { variantId, factorMap, questions });
        return;
      }
      index = Math.min(total-1, index+1);
      save();
      renderQ();
    });
  }

  renderQ();
}

function compute(test, answers, ctx){
  const questions = (ctx && ctx.questions) ? ctx.questions : test.questions;
  const qs = questions;
  const vals = answers.map((ai, idx) => {
    const opt = qs[idx].opts[ai];
    return opt == null ? 0 : opt.v;
  });

  // ---- SCL-90 (90题/30题): factor means + overall mean ----
  if(test.mode === "scl90"){
    const factorMap = (ctx && ctx.factorMap) ? ctx.factorMap : (test.variants && test.variants[0] ? test.variants[0].factorMap : []);
    const buckets = {};
    factorMap.forEach(k => { if(!buckets[k]) buckets[k] = []; });
    vals.forEach((v,i) => {
      const key = factorMap[i] || "other";
      if(!buckets[key]) buckets[key] = [];
      buckets[key].push(Number(v)||0);
    });

    const means = {};
    Object.entries(buckets).forEach(([k, arr]) => {
      if(!arr.length) return;
      means[k] = arr.reduce((a,b)=>a+b,0)/arr.length;
    });

    const overallMean = vals.reduce((a,b)=>a+(Number(b)||0),0) / Math.max(1, vals.length);
    const positiveCount = vals.filter(v => (Number(v)||0) >= 2).length;

    const level = overallMean >= 2 ? "较高" : (overallMean >= 1 ? "中等" : "较低");
    const top = Object.entries(means).sort((a,b)=>b[1]-a[1]).slice(0,3).map(x=>x[0]);

    const note = (level === "较高")
      ? "整体困扰偏高：建议尽快获得现实支持，并考虑专业咨询/评估。"
      : (level === "中等")
        ? "整体困扰中等：建议关注睡眠、压力源与情绪调节策略。"
        : "整体困扰较低：建议保持作息与自我观察。";

    return { kind:"scl90", overallMean, means, level, positiveCount, top, text: note, variantId: ctx?.variantId || null };
  }

  // ---- Animal personality (type + axis) ----
  if(test.mode === "animal"){
    const counts = {};
    const axis = { ind:0, soc:0, plan:0 };
    vals.forEach(v => {
      const t = v && typeof v === "object" ? v.type : v;
      counts[t] = (counts[t]||0) + 1;
      const ax = (v && typeof v === "object") ? v.axis : null;
      if(ax){
        axis.ind += Number(ax.ind||0);
        axis.soc += Number(ax.soc||0);
        axis.plan += Number(ax.plan||0);
      }
    });
    const sorted = Object.entries(counts).sort((a,b)=>b[1]-a[1]);
    const main = sorted[0]?.[0] || "cat";
    const sub = sorted[1]?.[0] || sorted[0]?.[0] || "dog";
    // Check rule.types first (new location), then content.types (old location) for backwards compatibility
    const types = (test.rule && test.rule.types) ? test.rule.types : 
                  (test.content && test.content.types) ? test.content.types : [];
    const mainInfo = types.find(x=>x.key===main) || types[0];
    const subInfo = types.find(x=>x.key===sub) || types[1] || types[0];

    // normalize axis to 0~1 using rough bounds
    const norm = (x, min, max) => (x - min) / (max - min);
    const profile = {
      independence: clamp01(norm(axis.ind, -20, 28)),
      social: clamp01(norm(axis.soc, -18, 28)),
      planning: clamp01(norm(axis.plan, -12, 32)),
    };

    return { kind:"animal", main, sub, mainInfo, subInfo, profile, text:"主动物代表你的默认模式，副动物代表你在特定情境下的补位策略。" };
  }

  // ---- Age gap preference ----
  if(test.mode === "age_gap"){
    const score = { older:0, younger:0, equal:0, flex:0 };
    const axis = { stability:0, play:0, growth:0 };

    vals.forEach(v => {
      if(v && typeof v === "object"){
        const p = v.pref;
        if(score[p] != null) score[p] += 2;
        else score.flex += 1;
        const ax = v.axis || {};
        axis.stability += Number(ax.stability||0);
        axis.play += Number(ax.play||0);
        axis.growth += Number(ax.growth||0);
      }
    });

    const sorted = Object.entries(score).sort((a,b)=>b[1]-a[1]);
    const top = sorted[0][0];
    const labels = test.rule?.labels || {};
    const headline = labels[top]?.name || "倾向结果";
    const desc = labels[top]?.desc || "";

    // three focus points by axis
    const pSt = clamp01((axis.stability + 18) / 36);
    const pPl = clamp01((axis.play + 18) / 36);
    const pGr = clamp01((axis.growth + 18) / 36);
    const points = [
      {k:"稳定需求", v:pSt, tip: pSt>0.62 ? "你更需要确定性与可预期的承诺。" : "你对不确定性有一定容忍度。"},
      {k:"活力需求", v:pPl, tip: pPl>0.62 ? "你更需要有趣的互动与轻松氛围。" : "你更重相处质量而非刺激度。"},
      {k:"成长需求", v:pGr, tip: pGr>0.62 ? "你更在意共同成长与复盘机制。" : "你更在意当下的舒适与节奏。"},
    ];

    const advice = (top==="older")
      ? ["把“依赖”变成“协商”：明确你需要对方兜底的部分。","避免把对方当父母：保留自我负责的空间。","冲突时用“流程沟通”：事实—感受—请求。"]
      : (top==="younger")
        ? ["把热烈变成稳定：约定沟通频率与底线。","避免情绪化决定：给自己 24 小时缓冲。","保留共同成长：一起做一个长期小目标。"]
        : (top==="equal")
          ? ["把平等落地：把规则写清楚而不是默认懂。","冲突要复盘：用“问题—方案—执行”闭环。","保持仪式感：别把亲密变成纯协作。"]
          : ["按阶段调参：不同阶段需要不同节奏与边界。","减少自我怀疑：偏好变化是正常的。","把“合拍”拆成指标：稳定、快乐、成长。"];

    return { kind:"age_gap", top, headline, desc, points, advice, text:"偏好不是枷锁：它只是你当前更舒服的互动结构。" };
  }

  // ---- fallbacks (original modes) ----
  if(test.mode === "scale_sum"){
    const sum = vals.reduce((a,b)=>a+(Number(b)||0),0);
    const band = (test.rule.bands || []).find(([min,max]) => sum>=min && sum<=max) || null;
    return { kind:"sum", score:sum, level: band ? band[2] : "结果", text: band ? band[3] : "已完成测评。" };
  }

  if(test.mode === "mbti"){
    const dims = [["E","I"],["S","N"],["T","F"],["J","P"]];
    const counts = {E:0,I:0,S:0,N:0,T:0,F:0,J:0,P:0};
    answers.forEach((ai, i) => {
      const pick = (qs[i].opts[ai] || {}).v;
      const [a,b] = dims[i % 4];
      if(pick === "A") counts[a] += 1; else counts[b] += 1;
    });
    const type = [
      counts.E >= counts.I ? "E" : "I",
      counts.S >= counts.N ? "S" : "N",
      counts.T >= counts.F ? "T" : "F",
      counts.J >= counts.P ? "J" : "P",
    ].join("");
    return { kind:"mbti", type, text: test.rule?.note || "这是一个倾向结果，建议结合自我观察。" };
  }

  if(test.mode === "holland"){
    const types = ["R","I","A","S","E","C"];
    const scores = {R:0,I:0,A:0,S:0,E:0,C:0};
    vals.forEach((v,i)=>{ scores[types[i%6]] += Number(v)||0; });
    const top = Object.entries(scores).sort((a,b)=>b[1]-a[1]).slice(0,3);
    const code = top.map(x=>x[0]).join("");
    return { kind:"holland", code, scores, text:"你可以把兴趣代码作为职业探索的起点。" };
  }

  if(test.mode === "attachment"){
    const buckets = ["secure","anxious","avoidant"];
    const score = {secure:0, anxious:0, avoidant:0};
    vals.forEach((v,i)=> score[buckets[i%3]] += Number(v)||0);
    const top = Object.entries(score).sort((a,b)=>b[1]-a[1])[0][0];
    const info = (test.rule.types || []).find(x => x[0] === top);
    return { kind:"attachment", type: top, title: info?.[1] || top, text: info?.[2] || "" };
  }

  if(test.mode === "bem"){
    let m=0,f=0;
    vals.forEach((v,i)=> (i%2===0? m+=Number(v)||0 : f+=Number(v)||0));
    let label = "双性化";
    if(m - f >= 3) label = "阳刚倾向";
    else if(f - m >= 3) label = "阴柔倾向";
    else if(m<=2 && f<=2) label = "未分化";
    return { kind:"bem", m, f, level: label, text:"这是特质倾向的简化展示，建议结合情境理解。" };
  }

  if(test.mode === "dark_triad"){
    const dims = (test.rule.dims || []).map(d=>d[0]);
    const score = {};
    dims.forEach(d=>score[d]=0);
    vals.forEach((v,i)=> score[dims[i%dims.length]] += Number(v)||0);
    const top = Object.entries(score).sort((a,b)=>b[1]-a[1])[0][0];
    return { kind:"dark", top, score, text:"倾向不等于标签，可用于反思而非自我否定。" };
  }

  if(test.mode === "traits"){
    const traits = (test.rule.traits || []).map(t=>t[0]);
    const score = {};
    traits.forEach(t=>score[t]=0);
    vals.forEach((v,i)=> score[traits[i%traits.length]] += Number(v)||0);
    const top = Object.entries(score).sort((a,b)=>b[1]-a[1])[0][0];
    return { kind:"traits", top, score, text:"优势来自长期训练与反馈循环，你可以据此做刻意练习。" };
  }

  if(test.mode === "dimension"){
    const dims = (test.rule.dims || []).map(d=>d[0]);
    const score = {};
    dims.forEach(d=>score[d]=0);
    vals.forEach((v,i)=> score[dims[i%dims.length]] += Number(v)||0);
    const top = Object.entries(score).sort((a,b)=>b[1]-a[1])[0][0];

    const results = test.rule.results || [];
    let hit = results[0];
    if(results.length === 3){
      if(top === dims[0]) hit = results[0];
      if(top === dims[1]) hit = results[1];
      if(top === dims[2]) hit = results[2];
    }else if(results.length === 2){
      hit = results[ score[dims[0]] >= score[dims[1]] ? 0 : 1 ];
    }
    const headline = hit ? hit[0] : "倾向结果";
    const text = hit ? hit[1] : "已生成倾向结果。";
    return { kind:"dimension", title: headline, text };
  }

  if(test.mode === "fun_persona"){
    const sum = vals.reduce((a,b)=>a+(Number(b)||0),0);
    const personas = test.rule.personas || [];
    const pick = personas.length ? personas[sum % personas.length] : ["结果","描述",""];
    return { kind:"persona", title: pick[0], subtitle: pick[1], text: pick[2] };
  }

  return { kind:"done", text:"完成" };
}

function saveHistory(test, result){
  const histKey = keyFor(test.id, "history");
  const history = loadJSON(histKey, []);
  const time = new Date();
  const pad = (n) => String(n).padStart(2,"0");
  const stamp = `${time.getFullYear()}-${pad(time.getMonth()+1)}-${pad(time.getDate())} ${pad(time.getHours())}:${pad(time.getMinutes())}`;
  const share = `${location.origin}${viewPath(test.id)}`;

  history.unshift({
    title: result.level || result.type || result.title || result.code || "已完成",
    time: stamp,
    share,
    result
  });
  saveJSON(histKey, history.slice(0, 20));
}

function showResult(test, result, fromHistory=false){
  $("#panelTitle").textContent = "结果";
  $("#progressText").textContent = "";

  const share = `${location.origin}${viewPath(test.id)}`;

  // --- Custom: SCL-90 ---
  if(result.kind === "scl90"){
    const factorMeta = {
      som:{name:"躯体化"}, oc:{name:"强迫倾向"}, is:{name:"人际敏感"}, dep:{name:"抑郁倾向"}, anx:{name:"焦虑紧张"},
      hos:{name:"敌对易怒"}, pho:{name:"恐惧回避"}, par:{name:"猜疑偏执"}, psy:{name:"疏离怪异感"}
    };
    const list = Object.entries(result.means || {}).sort((a,b)=>b[1]-a[1]);

    const bars = list.map(([k, v]) => {
      const name = factorMeta[k]?.name || k;
      const p = clamp01((v)/4);
      const tag = v>=2 ? "偏高" : (v>=1 ? "中等" : "较低");
      return `
        <div class="factor">
          <div class="row">
            <div class="n">${escapeHtml(name)}</div>
            <div class="small">${tag} · 均分 ${v.toFixed(2)}</div>
          </div>
          <div style="height:8px"></div>
          <div class="meter" aria-label="${escapeHtml(name)}"><div style="width:${pct(p)}"></div></div>
        </div>
      `;
    }).join("");

    const variantName = result.variantId === "quick" ? "速测版" : "完整版";
    $("#panelBody").innerHTML = `
      <div class="question">
        <div class="q-top">
          <div class="q-num">总览</div>
          <div class="small">${fromHistory ? "来自历史记录" : `本次：${escapeHtml(variantName)}`}</div>
        </div>
        <h3 class="result-title">${escapeHtml(result.level)}（总均分 ${result.overallMean.toFixed(2)}）</h3>
        <p class="result-text">${escapeHtml(result.text || "")}</p>
        <div class="result-badges">
          <span class="result-badge">阳性条目（≥2分）：${result.positiveCount}</span>
          <span class="result-badge">建议关注 TOP：${(result.top||[]).map(k=>escapeHtml(factorMeta[k]?.name||k)).join("、")}</span>
        </div>
      </div>

      <div style="margin-top:12px">
        <details class="details" open>
          <summary>分维度概览 <span class="small">（点击折叠）</span></summary>
          <div class="details-b">
            <div class="small">均分越高表示近期困扰越强。建议关注前三个维度，并结合生活事件理解。</div>
            <div class="factor-list">${bars}</div>
          </div>
        </details>

        <details class="details">
          <summary>下一步建议 <span class="small">（点击展开）</span></summary>
          <div class="details-b">
            <ul style="margin:8px 0 0;padding-left:18px">
              <li>优先处理睡眠：固定起床时间、减少睡前刺激、必要时寻求帮助。</li>
              <li>把压力源写下来：分成“能做的/不可控的”，先做可控部分。</li>
              <li>如果总均分持续 ≥ 2 或出现自伤想法，请优先联系专业支持。</li>
            </ul>
          </div>
        </details>
      </div>
    `;

    $("#panelActions").innerHTML = `
      <a class="btn btn-ghost" href="#" data-copy="${escapeHtml(share)}">复制链接</a>
      <button class="btn btn-primary" id="againBtn" type="button">再测一次</button>
      <a class="btn btn-ghost" href="/">先回测评库看看</a>
    `;

    document.querySelector('[data-copy]')?.addEventListener("click", async (e) => {
      e.preventDefault();
      try{ await navigator.clipboard.writeText(share); toast("已复制"); }catch{ toast("复制失败"); }
    });
    $("#againBtn").addEventListener("click", () => {
      localStorage.removeItem(keyFor(test.id,"progress"));
      localStorage.removeItem(keyFor(test.id,"answers"));
      renderHome(test);
    });
    setupDetailsToggle();
    return;
  }

  // --- Custom: Animal ---
  if(result.kind === "animal"){
    const main = result.mainInfo || {};
    const sub = result.subInfo || {};
    const p = result.profile || { independence:0.5, social:0.5, planning:0.5 };

    const list = (arr)=> (arr||[]).slice(0,3).map(x=>`<li>${escapeHtml(x)}</li>`).join("");

    $("#panelBody").innerHTML = `
      <div class="question">
        <div class="q-top">
          <div class="q-num">主动物</div>
          <div class="small">${fromHistory ? "来自历史记录" : "已生成结果卡"}</div>
        </div>
        <h3 class="result-title">${escapeHtml(main.icon||"")} ${escapeHtml(main.name||"")}</h3>
        <p class="result-text">${escapeHtml(main.tagline||"")}</p>
        <div class="result-badges">
          <span class="result-badge">副动物：${escapeHtml(sub.icon||"")} ${escapeHtml(sub.name||"")}</span>
          <span class="result-badge">你可能会在不同场景切换模式</span>
        </div>
      </div>

      <div style="margin-top:12px">
        <details class="details" open>
          <summary>你的倾向画像 <span class="small">（点击折叠）</span></summary>
          <div class="details-b">
            <div class="small">不是好坏，只是偏好：看见它，才能更好地使用它。</div>
            <div style="margin-top:10px">
              <div class="small">独立感</div>
              <div class="meter"><div style="width:${pct(p.independence)}"></div></div>
              <div style="height:8px"></div>
              <div class="small">社交能量</div>
              <div class="meter"><div style="width:${pct(p.social)}"></div></div>
              <div style="height:8px"></div>
              <div class="small">计划偏好</div>
              <div class="meter"><div style="width:${pct(p.planning)}"></div></div>
            </div>
          </div>
        </details>

        <details class="details" open>
          <summary>优势与盲点 <span class="small">（点击折叠）</span></summary>
          <div class="details-b">
            <b>优势</b>
            <ul style="margin:6px 0 10px;padding-left:18px">${list(main.strength)}</ul>
            <b>容易踩坑</b>
            <ul style="margin:6px 0 0;padding-left:18px">${list(main.blind)}</ul>
          </div>
        </details>

        <details class="details" open>
          <summary>关系与工作建议 <span class="small">（点击折叠）</span></summary>
          <div class="details-b">
            <b>关系</b><div class="small" style="margin-top:6px">${escapeHtml(main.love||"")}</div>
            <b style="display:block;margin-top:10px">工作</b><div class="small" style="margin-top:6px">${escapeHtml(main.work||"")}</div>
            <div class="small" style="margin-top:10px">${escapeHtml(result.text||"")}</div>
          </div>
        </details>
      </div>
    `;

    $("#panelActions").innerHTML = `
      <a class="btn btn-ghost" href="#" data-copy="${escapeHtml(share)}">复制链接</a>
      <button class="btn btn-primary" id="againBtn" type="button">再测一次</button>
      <a class="btn btn-ghost" href="/">先回测评库看看</a>
    `;

    document.querySelector('[data-copy]')?.addEventListener("click", async (e) => {
      e.preventDefault();
      try{ await navigator.clipboard.writeText(share); toast("已复制"); }catch{ toast("复制失败"); }
    });
    $("#againBtn").addEventListener("click", () => {
      localStorage.removeItem(keyFor(test.id,"progress"));
      localStorage.removeItem(keyFor(test.id,"answers"));
      renderHome(test);
    });
    setupDetailsToggle();
    return;
  }

  // --- Custom: Age preference ---
  if(result.kind === "age_gap"){
    const points = (result.points||[]).map(p => `
      <div class="factor">
        <div class="row">
          <div class="n">${escapeHtml(p.k)}</div>
          <div class="small">${pct(p.v)}</div>
        </div>
        <div style="height:8px"></div>
        <div class="meter"><div style="width:${pct(p.v)}"></div></div>
        <div class="d">${escapeHtml(p.tip||"")}</div>
      </div>
    `).join("");

    const advice = (result.advice||[]).map(x=>`<li>${escapeHtml(x)}</li>`).join("");

    $("#panelBody").innerHTML = `
      <div class="question">
        <div class="q-top">
          <div class="q-num">偏好类型</div>
          <div class="small">${fromHistory ? "来自历史记录" : "已生成结果卡"}</div>
        </div>
        <h3 class="result-title">${escapeHtml(result.headline || "倾向结果")}</h3>
        <p class="result-text">${escapeHtml(result.desc || "")}</p>
        <div class="result-badges">
          <span class="result-badge">三项关键点：稳定 / 活力 / 成长</span>
          <span class="result-badge">${escapeHtml(result.text || "")}</span>
        </div>
      </div>

      <div style="margin-top:12px">
        <details class="details" open>
          <summary>你的相处关键点 <span class="small">（点击折叠）</span></summary>
          <div class="details-b">
            <div class="factor-list">${points}</div>
          </div>
        </details>

        <details class="details">
          <summary>提升建议 <span class="small">（点击展开）</span></summary>
          <div class="details-b">
            <ul style="margin:8px 0 0;padding-left:18px">${advice}</ul>
          </div>
        </details>
      </div>
    `;

    $("#panelActions").innerHTML = `
      <a class="btn btn-ghost" href="#" data-copy="${escapeHtml(share)}">复制链接</a>
      <button class="btn btn-primary" id="againBtn" type="button">再测一次</button>
      <a class="btn btn-ghost" href="/">先回测评库看看</a>
    `;

    document.querySelector('[data-copy]')?.addEventListener("click", async (e) => {
      e.preventDefault();
      try{ await navigator.clipboard.writeText(share); toast("已复制"); }catch{ toast("复制失败"); }
    });
    $("#againBtn").addEventListener("click", () => {
      localStorage.removeItem(keyFor(test.id,"progress"));
      localStorage.removeItem(keyFor(test.id,"answers"));
      renderHome(test);
    });
    setupDetailsToggle();
    return;
  }

  // ---- Default rendering ----
  let headline = "已完成";
  let sub = "";
  let detail = result.text || "";

  if(result.kind === "sum"){
    headline = `${result.level}（总分 ${result.score}）`;
    sub = "强度参考";
  }else if(result.kind === "mbti"){
    headline = `${result.type} 倾向`;
    sub = "16 型倾向";
  }else if(result.kind === "holland"){
    headline = `RIASEC：${result.code}`;
    sub = "职业兴趣代码";
  }else if(result.kind === "attachment"){
    headline = `${result.title}`;
    sub = "依恋倾向";
  }else if(result.kind === "bem"){
    headline = `${result.level}`;
    sub = `阳刚 ${result.m} / 阴柔 ${result.f}`;
  }else if(result.kind === "dark"){
    headline = `更突出：${result.top}`;
    sub = "倾向概览";
  }else if(result.kind === "traits"){
    headline = `优势更偏：${result.top}`;
    sub = "特质概览";
  }else if(result.kind === "persona"){
    headline = result.title;
    sub = result.subtitle || "你的类型";
  }else if(result.kind === "dimension"){
    headline = result.title || "倾向结果";
    sub = "关系/偏好";
  }

  $("#panelBody").innerHTML = `
    <div class="question">
      <div class="q-top">
        <div class="q-num">${escapeHtml(sub || "结果")}</div>
        <div class="small">${fromHistory ? "来自历史记录" : "已生成结果卡"}</div>
      </div>
      <h3 class="result-title">${escapeHtml(headline)}</h3>
      <p class="result-text">${escapeHtml(detail)}</p>
      <div class="result-badges">
        <span class="result-badge">可复制链接分享</span>
        <span class="result-badge">可返回重测</span>
        <span class="result-badge">本地历史</span>
      </div>
    </div>
  `;

  $("#panelActions").innerHTML = `
    <a class="btn btn-ghost" href="#" data-copy="${escapeHtml(share)}">复制链接</a>
    <button class="btn btn-primary" id="againBtn" type="button">再测一次</button>
    <a class="btn btn-ghost" href="/">先回测评库看看</a>
  `;

  document.querySelector('[data-copy]')?.addEventListener("click", async (e) => {
    e.preventDefault();
    try{ await navigator.clipboard.writeText(share); toast("已复制"); }catch{ toast("复制失败"); }
  });

  $("#againBtn").addEventListener("click", () => {
    localStorage.removeItem(keyFor(test.id,"progress"));
    localStorage.removeItem(keyFor(test.id,"answers"));
    renderHome(test);
  });
  
  setupDetailsToggle();
}

function submit(test, answers, ctx){
  const result = compute(test, answers, ctx);
  localStorage.removeItem(keyFor(test.id,"progress"));
  saveHistory(test, result);
  renderHistory(test);
  showResult(test, result, false);
  toast("已提交");
  // 上报测评完成事件
  try{
    fetch('/api/track.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body:JSON.stringify({eventType:'test_complete',testId:test.id,source:location.search})
    }).catch(()=>{});
  }catch{}
}

function syncAuthedViewUI(){
  const authed = isAuthed();
  const banner = document.getElementById("viewBottomBanner");
  const btn = document.getElementById("viewActivateBtn");
  if(authed){
    if(btn){ btn.textContent = "查看全部测评"; btn.href = "/#tests"; }
    if(banner){
      const t = banner.querySelector("strong");
      if(t) t.textContent = "你已解锁完整测评";
      const p = banner.querySelector("div");
      if(p) p.innerHTML = `<strong>你已解锁完整测评</strong><br/>可继续当前测试，或返回测评库选择其他项目。`;
    }
  }
}

function wireClearLocal(test){
  $("#clearLocal").addEventListener("click", () => clearLocalFor(test));
}

async function main(){
  localStorage.setItem("psy_last_path", location.pathname + location.search + location.hash);
  setBrand();
  loadSiteSettings();
  setupBubbles();
  authUI();

  const id = getTestId();
  if(!id){ location.href = "/"; return; }

  const test = findTest(id);
  if(!test){
    $("#title").textContent = "未找到该测评";
    $("#intro").textContent = "请返回首页选择测试。";
    $("#panelBody").innerHTML = `<div class="small">参数 id 无效：<span class="kbd">${escapeHtml(id)}</span></div>`;
    $("#panelActions").innerHTML = `<a class="btn btn-primary" href="/">回到首页</a>`;
    return;
  }

  document.body.dataset.testid = test.id;
  document.body.classList.add(`theme-${test.id}`);
  document.body.dataset.category = test.category;
  renderMeta(test);
  const hideBtn = document.getElementById("hideViewBanner");
  const banner = document.getElementById("viewBottomBanner");
  if(localStorage.getItem("psy_hide_view_banner") === "1" && banner) banner.style.display = "none";
  hideBtn?.addEventListener("click", ()=>{ if(banner) banner.style.display="none"; localStorage.setItem("psy_hide_view_banner","1"); });
  syncAuthedViewUI();
  renderHistory(test);
  wireClearLocal(test);

  if(!isAuthed()){
    if(_freePreviewQuestions > 0){
      // 先测试后引导购买：允许免费预览部分题目
      renderHome(test);
    } else {
      renderLocked(test);
    }
    return;
  }
  document.body.classList.add("is-authed");
  const check = await window.Auth.validate(false);
  if(!check.ok){
    renderLocked(test);
    authUI();
    return;
  }
  renderHome(test);
  bridgeToMiniProgram("open_test", { id: test.id });
}

function setupDetailsToggle(){
  document.querySelectorAll('.details').forEach(details => {
    details.addEventListener('toggle', function() {
      const summary = this.querySelector('summary .small');
      if (summary) {
        summary.textContent = this.open ? '（点击折叠）' : '（点击展开）';
      }
    });
  });
}

document.addEventListener("DOMContentLoaded", main);

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
(function(){
  const $ = (s)=>document.querySelector(s);

  function qs(name){ return new URL(location.href).searchParams.get(name); }
  function decodeRedirect(v){ try{return v?decodeURIComponent(v):"";}catch{return "";} }
  function fmt(ts){
    if(!ts) return "";
    const d = new Date(ts*1000); const p = n => String(n).padStart(2,'0');
    return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`;
  }
  function toast(msg){
    const el = document.createElement("div");
    el.textContent = msg;
    el.style.cssText = "position:fixed;left:50%;top:18px;transform:translateX(-50%);z-index:9999;background:rgba(10,10,10,.88);color:#fff;padding:10px 12px;border-radius:12px;font-size:13px;max-width:92vw";
    document.body.appendChild(el);
    setTimeout(()=>el.remove(), 1800);
  }

  const input = $("#codeInput");
  const btn = $("#activateBtn");
  const clearBtn = $("#clearBtn");
  const status = $("#status");
  const back = $("#backToTest");
  const tip = $("#redirectTip");

  const redirectRaw = qs("redirect");
  const lastVisited = localStorage.getItem("psy_last_path") || "";
  const redirect = decodeRedirect(redirectRaw) || lastVisited || "/";
  if(back){ back.href = redirect; back.textContent = "返回上一步"; }
  if(tip){ tip.textContent = redirect ? `激活成功后可返回：${redirect}` : "激活成功后将优先返回你最近浏览的页面。"; }

  async function refresh(){
    const a = window.Auth?.getAuth?.();
    if(a?.ok){
      const ck = await window.Auth.validate(true);
      const exp = ck?.exp || a.exp;
      const code = ck?.data?.code || '当前会话';
      status.innerHTML = `已激活 · 会话：<span class="kbd">${code}</span> · 有效至 <span class="kbd">${fmt(exp)}</span>`;
      btn.textContent = "已激活";
      btn.disabled = true;
      input && (input.disabled = true);
      clearBtn.style.display = "";
      tip && (tip.textContent = `你当前可直接查看权限状态；需要继续测评时再点击“返回上一步”。`);
    }else{
      status.textContent = "未激活";
      btn.textContent = "立即激活";
      btn.disabled = false;
      input && (input.disabled = false);
      clearBtn.style.display = "";
    }
  }

async function activate(){
    let code = String(input?.value||"").trim();
    code = code.replace(/[–—―\s]+/g, "-").toUpperCase();
    if(input) input.value = code;
    if(!code){ toast("请输入激活码"); input?.focus(); return; }
    btn.disabled = true; btn.textContent = "处理中…";
    try{
      const res = await window.Auth.redeem(code);
      if(!res.ok){
        status.textContent = res.data?.message || res.data?.error || "激活失败";
        toast(status.textContent);
        return;
      }
      toast("激活成功");
      await refresh();

    }catch(e){
      status.textContent = "网络异常，请稍后重试";
      toast(status.textContent);
    }finally{
      btn.disabled = false;
      if(!(window.Auth?.getAuth?.()?.ok)) btn.textContent = "立即激活";
      else btn.textContent = "已激活";
    }
  }

  btn?.addEventListener("click", activate);
  input?.addEventListener("keydown", (e)=>{ if(e.key === "Enter"){ e.preventDefault(); activate(); }});
  clearBtn?.addEventListener("click", async ()=>{ await window.Auth.logout(); toast("已退出当前设备登录"); refresh(); });

  loadSiteSettings();
  refresh();
})();

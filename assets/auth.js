// Server-side activation (C 方案)：
// - POST /api/redeem.php  { code } -> { ok, token, expiresAt }
// - POST /api/validate.php { token } -> { ok, expiresAt }
// - POST /api/logout.php { token } -> { ok }

(function(){
  const AUTH_KEY = "authToken";
  const EXP_KEY  = "authExpiresAt";
  const LAST_VALID_KEY = "authLastValidatedAt";

  const API = {
    redeem: "/api/redeem.php",
    validate: "/api/validate.php",
    logout: "/api/logout.php",
  };

  function nowSec(){ return Math.floor(Date.now()/1000); }

  function getAuth(){
    const token = localStorage.getItem(AUTH_KEY) || "";
    const exp = Number(localStorage.getItem(EXP_KEY) || "0");
    if(!token) return { ok:false };
    if(exp && exp < nowSec()) return { ok:false, expired:true };
    return { ok:true, token, exp };
  }

  function setAuth(token, exp){
    localStorage.setItem(AUTH_KEY, token);
    localStorage.setItem(EXP_KEY, String(exp||0));
    localStorage.setItem(LAST_VALID_KEY, String(nowSec()));
  }

  function clearAuth(){
    localStorage.removeItem(AUTH_KEY);
    localStorage.removeItem(EXP_KEY);
    localStorage.removeItem(LAST_VALID_KEY);
  }

  async function postJson(url, body){
    const r = await fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body || {})
    });
    const j = await r.json().catch(()=>({ ok:false, error:"bad_json" }));
    return { ok: r.ok && j.ok, status: r.status, data: j };
  }

  async function redeem(code){
    // Capture UTM parameters and referral code
    const params = new URLSearchParams(window.location.search);
    const extra = {};
    if(params.get('ref')) extra.refCode = params.get('ref');
    if(params.get('utm_source')) extra.utmSource = params.get('utm_source');
    if(params.get('utm_medium')) extra.utmMedium = params.get('utm_medium');
    if(params.get('utm_campaign')) extra.utmCampaign = params.get('utm_campaign');
    extra.source = extra.refCode ? 'affiliate' : (document.referrer ? 'referrer' : 'direct');

    const res = await postJson(API.redeem, { code: String(code||"").trim(), ...extra });
    if(!res.ok) return res;
    const j = res.data;
    setAuth(j.token, j.expiresAt);
    return res;
  }

  async function validate(force=false){
    const a = getAuth();
    if(!a.ok) return { ok:false, error:"no_auth" };

    // 10 分钟内不重复验证（减少请求）
    const last = Number(localStorage.getItem(LAST_VALID_KEY) || "0");
    if(!force && last && (nowSec() - last) < 600){
      return { ok:true, cached:true, exp:a.exp };
    }

    const res = await postJson(API.validate, { token: a.token });
    if(!res.ok){
      clearAuth();
      return res;
    }
    const j = res.data;
    setAuth(a.token, j.expiresAt || a.exp);
    return { ok:true, exp:j.expiresAt || a.exp, data:j };
  }

  function buildRedirectParam(){
    const p = location.pathname + location.search + location.hash;
    return encodeURIComponent(p);
  }

  async function ensureAuthOrRedirect(){
    const a = getAuth();
    if(!a.ok){
      location.href = "/code?redirect=" + buildRedirectParam();
      return false;
    }
    const v = await validate(false);
    if(!v.ok){
      location.href = "/code?redirect=" + buildRedirectParam();
      return false;
    }
    return true;
  }

  async function logout(){
    const a = getAuth();
    if(a.ok){
      await postJson(API.logout, { token: a.token }).catch(()=>{});
    }
    clearAuth();
  }

  // expose
  window.Auth = { getAuth, redeem, validate, ensureAuthOrRedirect, logout };
})();

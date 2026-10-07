"use strict";

const RAFRAICHISSEMENT_MS = 4000;
const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) =>
  ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const nb = (n) => Math.round(n).toLocaleString("fr-FR");
const pct = (x) => (Math.round(x * 1000) / 10).toLocaleString("fr-FR");
const fmtCote = (c) => c.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const gainDe = (montant, cote) => Math.floor(montant * Math.round(cote * 100) / 100); // calcul exact, comme le serveur
const fmtDate = (s) => s ? new Date(s).toLocaleString("fr-FR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "";

let etat = null;
let onglet = "ouverts";

// ---------------------------------------------------------------------------
// Appels serveur
// ---------------------------------------------------------------------------

async function api(chemin, { method, body } = {}) {
  const r = await fetch("api.php?r=" + chemin, {
    method: method || (body ? "POST" : "GET"),
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
    credentials: "same-origin",
  });
  const donnees = await r.json().catch(() => ({}));
  if (!r.ok) throw new Error(donnees.erreur || `Erreur ${r.status}`);
  return donnees;
}

async function action(promesse, messageSucces) {
  try {
    await promesse;
    if (messageSucces) toast(messageSucces, "succes");
    await rafraichir();
    return true;
  } catch (e) {
    toast(e.message, "erreur");
    return false;
  }
}

let toastTimer;
function toast(texte, type = "") {
  const el = $("#toast");
  el.textContent = texte;
  el.className = "toast " + type;
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => (el.hidden = true), 3500);
}

async function rafraichir() {
  try {
    etat = await api("etat");
    $(".direct").classList.remove("hors-ligne");
    rendre();
  } catch {
    $(".direct").classList.add("hors-ligne");
  }
}

// ---------------------------------------------------------------------------
// Rendu : on ne remplace le HTML que s'il a changé, en conservant la saisie
// en cours (champs marqués data-k) pour ne pas gêner l'utilisateur.
// ---------------------------------------------------------------------------

function patch(el, html) {
  if (el._html === html) return;
  const valeurs = {};
  $$("[data-k]", el).forEach((i) => (valeurs[i.dataset.k] = i.value));
  const focus = el.contains(document.activeElement) ? document.activeElement.dataset.k : null;
  el.innerHTML = html;
  el._html = html;
  $$("[data-k]", el).forEach((i) => {
    if (i.dataset.k in valeurs && valeurs[i.dataset.k] !== "") i.value = valeurs[i.dataset.k];
  });
  if (focus) $(`[data-k="${focus}"]`, el)?.focus();
}

function rendre() {
  $("#capital").textContent = nb(etat.capital);
  $("#onglet-admin").hidden = !etat.admin && onglet !== "admin";
  $("#admin-connexion").hidden = etat.admin;
  $("#admin-contenu").hidden = !etat.admin;
  rendreCompte();
  rendreOnglets();
  const actifs = etat.paris.filter((p) => p.statut === "ouvert" || p.statut === "suspendu");
  const clos = etat.paris.filter((p) => p.statut === "clos" || p.statut === "annule");
  patch($("#vue-ouverts"), listeParis(actifs, "Aucun pari en cours pour l'instant."));
  patch($("#vue-clos"), listeParis(clos, "Aucun pari clôturé pour l'instant."));
  patch($("#vue-mes-mises"), vueMesMises());
  patch($("#classement"), vueClassement());
  patch($("#fil"), vueFil());
  if (etat.admin) patch($("#admin-paris"), vueAdminParis());
  $("#categories").innerHTML = [...new Set(etat.paris.map((p) => p.categorie).filter(Boolean))]
    .map((c) => `<option value="${esc(c)}">`).join("");
  majGainsPotentiels();
}

function rendreCompte() {
  const moi = etat.moi;
  const html = moi
    ? `<div class="solde">
         <div class="bloc"><small>Disponible</small><b>${nb(moi.disponible)} 🔔</b></div>
         <div class="bloc"><small>En jeu</small><b>${nb(moi.en_jeu)}</b></div>
         <div class="bloc"><small>Rang</small><b>${moi.rang}<sup>${moi.rang === 1 ? "er" : "e"}</sup></b></div>
       </div>
       <div><b>${esc(moi.pseudo)}</b><br><button class="lien" data-action="deconnexion">Déconnexion</button></div>`
    : `<form id="form-joueur">
         <input data-k="pseudo" name="pseudo" placeholder="Pseudo" autocomplete="username" required>
         <input data-k="pin" name="pin" type="password" placeholder="Code secret" autocomplete="current-password" required>
         <button type="submit" data-mode="connexion">Se connecter</button>
         <button type="submit" data-mode="inscription" class="secondaire">Créer un compte</button>
       </form>`;
  patch($("#compte"), html);
}

function rendreOnglets() {
  const ouverts = etat.paris.filter((p) => p.accepte_mises).length;
  $('[data-onglet="ouverts"]').innerHTML = `Paris en cours<span class="compteur">${ouverts}</span>`;
  $$("#onglets button").forEach((b) => b.classList.toggle("actif", b.dataset.onglet === onglet));
  $$(".vue").forEach((v) => (v.hidden = v.id !== "vue-" + onglet));
}

function badgeStatut(p) {
  if (p.statut === "ouvert" && !p.accepte_mises) return `<span class="badge ferme">Mises closes</span>`;
  const libelles = { ouvert: "Ouvert", suspendu: "Suspendu", clos: "Clôturé", annule: "Annulé · remboursé" };
  return `<span class="badge ${p.statut}">${libelles[p.statut]}</span>`;
}

function listeParis(paris, messageVide) {
  if (!paris.length) return `<p class="vide">${messageVide}</p>`;
  return `<div class="liste-paris">${paris.map(cartePari).join("")}</div>`;
}

function cartePari(p) {
  const meta = [];
  if (p.date_limite && p.statut === "ouvert") meta.push(`${p.accepte_mises ? "Mises jusqu'au" : "Mises closes le"} ${fmtDate(p.date_limite)}`);
  if (p.clos_le) meta.push(`Clôturé le ${fmtDate(p.clos_le)}`);
  meta.push(`${p.nb_joueurs} joueur${p.nb_joueurs > 1 ? "s" : ""} · ${nb(p.total_mise)} 🔔`);
  return `
    <article class="carte pari">
      <div class="pari-entete">
        ${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}
        ${badgeStatut(p)}
        <span class="meta">${meta.join(" · ")}</span>
      </div>
      <h3>${esc(p.titre)}</h3>
      ${p.description ? `<p class="desc">${esc(p.description)}</p>` : ""}
      <div class="issues">${p.issues.map((i) => ligneIssue(p, i)).join("")}</div>
    </article>`;
}

function ligneIssue(p, i) {
  const part = p.total_mise ? i.total_mise / p.total_mise : 0;
  const classe = p.statut === "clos" ? (i.id === p.issue_gagnante_id ? "gagnante" : "perdante") : "";
  const miennes = (etat.mes_mises || []).filter((m) => m.issue_id === i.id);
  const maMise = miennes.reduce((s, m) => s + m.montant, 0);
  const monGain = miennes.reduce((s, m) => s + gainDe(m.montant, m.cote), 0);
  let position = "";
  if (maMise) {
    position = p.statut === "clos" || p.statut === "annule"
      ? `Votre mise : ${nb(maMise)} 🔔 → ${nb(miennes.reduce((s, m) => s + (m.gain || 0), 0))} 🔔 récupérées`
      : `Votre mise : ${nb(maMise)} 🔔 · gain si réalisé : ${nb(monGain)} 🔔`;
  }
  const peutMiser = p.accepte_mises && etat.moi;
  return `
    <div class="issue ${classe}">
      <div class="barre" style="width:${(part * 100).toFixed(1)}%"></div>
      <div>
        <div class="libelle">${esc(i.libelle)}</div>
        <div class="stats">Probabilité ${pct(i.probabilite)} % · ${i.nb_joueurs} joueur${i.nb_joueurs > 1 ? "s" : ""} · ${nb(i.total_mise)} 🔔 (${pct(part)} %)</div>
        ${position ? `<div class="ma-position">${position}</div>` : ""}
      </div>
      <div class="cote">×${fmtCote(i.cote)}</div>
      ${peutMiser ? `
        <form class="miser" data-issue="${i.id}">
          <input type="number" min="1" step="1" max="${etat.moi.disponible}" placeholder="Mise" data-k="m-${i.id}" data-cote="${i.cote}">
          <button type="submit" ${etat.moi.disponible < 1 ? "disabled" : ""}>Parier</button>
          <span class="gain" data-gain="${i.id}"></span>
        </form>` : ""}
    </div>`;
}

function majGainsPotentiels() {
  $$(".miser input").forEach((input) => {
    const montant = parseInt(input.value, 10);
    const cible = $(`[data-gain="${input.dataset.k.slice(2)}"]`);
    if (cible) cible.textContent = montant > 0 ? `→ gain potentiel : ${nb(gainDe(montant, parseFloat(input.dataset.cote)))} 🔔` : "";
  });
}

function vueMesMises() {
  if (!etat.moi) return `<p class="vide">Connectez-vous ou créez un compte (en haut à droite) pour parier.</p>`;
  const mises = etat.mes_mises;
  if (!mises.length) return `<p class="vide">Vous n'avez encore rien misé. Rendez-vous dans « Paris en cours » !</p>`;
  const lignes = mises.map((m) => {
    let resultat;
    if (m.statut_pari === "annule") resultat = `<span>remboursé</span>`;
    else if (m.gain === null) resultat = `<span class="aide">en cours · ${nb(gainDe(m.montant, m.cote))} si gagné</span>`;
    else if (m.gain > 0) resultat = `<span class="gain-positif">+${nb(m.gain)}</span>`;
    else resultat = `<span class="gain-nul">perdu</span>`;
    return `<tr>
      <td>${fmtDate(m.cree_le)}</td><td>${esc(m.pari_titre)}</td><td>${esc(m.issue_libelle)}</td>
      <td class="nombre">${nb(m.montant)}</td><td class="nombre">×${fmtCote(m.cote)}</td><td class="nombre">${resultat}</td>
    </tr>`;
  }).join("");
  return `<div class="carte tableau-conteneur"><table>
    <thead><tr><th>Date</th><th>Pari</th><th>Issue</th><th class="nombre">Mise</th><th class="nombre">Cote</th><th class="nombre">Résultat</th></tr></thead>
    <tbody>${lignes}</tbody></table></div>`;
}

function vueClassement() {
  if (!etat.classement.length) return `<p class="vide">Aucun joueur inscrit.</p>`;
  const medailles = { 1: "🥇", 2: "🥈", 3: "🥉" };
  const lignes = etat.classement.map((j) => `
    <tr class="${etat.moi && j.id === etat.moi.id ? "moi" : ""}">
      <td class="rang">${medailles[j.rang] || j.rang}</td>
      <td>${esc(j.pseudo)}</td>
      <td class="nombre" title="Disponible : ${nb(j.disponible)} · En jeu : ${nb(j.en_jeu)}">${nb(j.total)}</td>
      <td class="nombre aide">${nb(j.en_jeu)}</td>
    </tr>`).join("");
  return `<div class="tableau-conteneur"><table>
    <thead><tr><th></th><th>Joueur</th><th class="nombre">🔔 Total</th><th class="nombre">En jeu</th></tr></thead>
    <tbody>${lignes}</tbody></table></div>`;
}

function vueFil() {
  if (!etat.fil.length) return `<li class="vide">Rien pour l'instant.</li>`;
  return etat.fil.map((e) => {
    let texte;
    if (e.type === "mise") texte = `<b>${esc(e.joueur)}</b> mise ${nb(e.montant)} 🔔 sur « ${esc(e.issue)} » — ${esc(e.pari)}`;
    else if (e.type === "clos") texte = `<span class="cloture">🏁 <b>${esc(e.pari)}</b> : « ${esc(e.issue)} ». ${e.nb_gagnants} mise${e.nb_gagnants > 1 ? "s" : ""} gagnante${e.nb_gagnants > 1 ? "s" : ""}, ${nb(e.distribue)} 🔔 versées.</span>`;
    else texte = `↩️ <b>${esc(e.pari)}</b> annulé, mises remboursées.`;
    return `<li><time>${fmtDate(e.date)}</time>${texte}</li>`;
  }).join("");
}

// ---------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------

function vueAdminParis() {
  const actifs = etat.paris.filter((p) => p.statut === "ouvert" || p.statut === "suspendu");
  const clos = etat.paris.filter((p) => p.statut === "clos" || p.statut === "annule");
  const cartes = actifs.map((p) => {
    const somme = p.issues.reduce((s, i) => s + i.probabilite, 0);
    return `
    <article class="carte admin-pari" data-pari="${p.id}">
      <div class="pari-entete">${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}${badgeStatut(p)}
        <span class="meta">${p.nb_joueurs} joueur(s) · ${nb(p.total_mise)} 🔔</span></div>
      <h3>${esc(p.titre)}</h3>
      <div class="tableau-conteneur"><table>
        <thead><tr><th>Issue</th><th>Proba. (%)</th><th class="nombre">Cote</th><th class="nombre">Misé</th><th class="nombre">À verser si réalisée</th></tr></thead>
        <tbody>${p.issues.map((i) => `<tr>
          <td>${esc(i.libelle)}</td>
          <td><input class="proba" type="number" step="0.1" min="0.1" max="99.9" data-k="p-${i.id}" data-issue="${i.id}" value="${Math.round(i.probabilite * 1000) / 10}"></td>
          <td class="nombre">×${fmtCote(i.cote)}</td>
          <td class="nombre">${nb(i.total_mise)}</td>
          <td class="nombre">${nb(i.a_verser ?? 0)}</td>
        </tr>`).join("")}</tbody>
      </table></div>
      <p class="aide">Somme des probabilités : ${pct(somme)} %. Les nouvelles cotes ne s'appliquent qu'aux mises futures.</p>
      <div class="actions">
        <label class="aide">Fin des mises <input type="datetime-local" data-k="d-${p.id}" value="${p.date_limite || ""}"></label>
        <button class="secondaire" data-action="admin-maj">Enregistrer</button>
        <button class="secondaire" data-action="admin-statut" data-statut="${p.statut === "ouvert" ? "suspendu" : "ouvert"}">
          ${p.statut === "ouvert" ? "Suspendre les mises" : "Rouvrir les mises"}</button>
      </div>
      <div class="actions">
        <select data-k="w-${p.id}"><option value="">— Issue réalisée —</option>
          ${p.issues.map((i) => `<option value="${i.id}">${esc(i.libelle)}</option>`).join("")}</select>
        <button data-action="admin-cloture">Clôturer et payer</button>
        <button class="danger" data-action="admin-annulation">Annuler (rembourser)</button>
        ${p.total_mise === 0 ? `<button class="lien" data-action="admin-suppression">Supprimer</button>` : ""}
      </div>
    </article>`;
  });
  const resumeClos = clos.length ? `<div class="carte"><h3>Paris clôturés</h3><ul>${clos.map((p) =>
    `<li>${esc(p.titre)} — ${p.statut === "annule" ? "annulé" : "« " + esc(p.issues.find((i) => i.id === p.issue_gagnante_id)?.libelle) + " »"}</li>`).join("")}</ul></div>` : "";
  return (cartes.join("") || `<p class="vide">Aucun pari en cours.</p>`) + resumeClos;
}

function ajouterLigneIssue(libelle = "", proba = "") {
  const ligne = document.createElement("div");
  ligne.className = "ligne-issue";
  ligne.innerHTML = `<input name="libelle" placeholder="Libellé de l'issue" value="${esc(libelle)}">
    <input name="probabilite" type="number" step="0.1" min="0.1" max="99.9" placeholder="%" value="${esc(proba)}">
    <button type="button" class="lien" data-action="retirer-issue">retirer</button>`;
  $("#issues-form").append(ligne);
  majSommeProbas();
}

function majSommeProbas() {
  const somme = $$('#issues-form [name="probabilite"]').reduce((s, i) => s + (parseFloat(i.value) || 0), 0);
  $("#somme-probas").textContent = `Somme : ${somme.toLocaleString("fr-FR")} %` + (Math.abs(somme - 100) > 0.5 ? " (idéalement 100 %)" : " ✓");
}

function reinitialiserFormPari() {
  $("#form-pari").reset();
  $("#issues-form").innerHTML = "";
  ajouterLigneIssue("Oui", 50);
  ajouterLigneIssue("Non", 50);
}

// ---------------------------------------------------------------------------
// Événements
// ---------------------------------------------------------------------------

document.addEventListener("submit", async (ev) => {
  const form = ev.target;
  ev.preventDefault();

  if (form.id === "form-joueur") {
    const mode = ev.submitter?.dataset.mode || "connexion";
    const body = { pseudo: form.pseudo.value, pin: form.pin.value };
    await action(api(mode, { body }), mode === "inscription" ? `Bienvenue ! ${etat.capital} clochettes vous attendent.` : "Connecté.");
  } else if (form.classList.contains("miser")) {
    const input = $("input", form);
    const montant = parseInt(input.value, 10);
    if (!(montant > 0)) return toast("Indiquez une mise d'au moins 1 clochette.", "erreur");
    input.value = ""; // vidé avant le rafraîchissement, qui conserve les saisies en cours
    const ok = await action(api("mises", { body: { issue_id: Number(form.dataset.issue), montant } }),
      `Mise de ${nb(montant)} 🔔 enregistrée !`);
    if (!ok) { const champ = $(`[data-k="m-${form.dataset.issue}"]`); if (champ) champ.value = montant; }
    majGainsPotentiels();
  } else if (form.id === "form-admin") {
    if (await action(api("admin/connexion", { body: { mot_de_passe: $("#admin-mdp").value } }), "Mode administration activé."))
      $("#admin-mdp").value = "";
  } else if (form.id === "form-pari") {
    const issues = $$(".ligne-issue", form).map((l) => ({ libelle: l.querySelector('[name="libelle"]').value, probabilite: l.querySelector('[name="probabilite"]').value }));
    const body = { titre: form.titre.value, categorie: form.categorie.value, description: form.description.value, date_limite: form.date_limite.value, issues };
    if (await action(api("admin/paris", { body }), "Pari créé.")) reinitialiserFormPari();
  }
});

document.addEventListener("click", async (ev) => {
  const bouton = ev.target.closest("[data-onglet], [data-action]");
  if (!bouton) return;
  if (bouton.dataset.onglet) {
    onglet = bouton.dataset.onglet;
    return rendre();
  }
  const carte = bouton.closest("[data-pari]");
  const pariId = carte?.dataset.pari;
  switch (bouton.dataset.action) {
    case "deconnexion":
      await action(api("deconnexion", { method: "POST" }));
      break;
    case "retirer-issue":
      bouton.closest(".ligne-issue").remove();
      majSommeProbas();
      break;
    case "admin-maj": {
      const probabilites = Object.fromEntries($$(".proba", carte).map((i) => [i.dataset.issue, i.value]));
      await action(api(`admin/paris/${pariId}/maj`, { body: { probabilites, date_limite: $(`[data-k="d-${pariId}"]`, carte).value } }), "Pari mis à jour.");
      break;
    }
    case "admin-statut":
      await action(api(`admin/paris/${pariId}/statut`, { body: { statut: bouton.dataset.statut } }));
      break;
    case "admin-cloture": {
      const select = $(`[data-k="w-${pariId}"]`, carte);
      if (!select.value) return toast("Choisissez d'abord l'issue réalisée.", "erreur");
      const libelle = select.selectedOptions[0].textContent;
      if (!confirm(`Clôturer ce pari avec l'issue « ${libelle} » ? Les gains seront versés immédiatement, c'est définitif.`)) return;
      await action(api(`admin/paris/${pariId}/cloture`, { body: { issue_id: Number(select.value) } }), "Pari clôturé, gains versés.");
      break;
    }
    case "admin-annulation":
      if (!confirm("Annuler ce pari et rembourser toutes les mises ? C'est définitif.")) return;
      await action(api(`admin/paris/${pariId}/annulation`, { method: "POST" }), "Pari annulé, mises remboursées.");
      break;
    case "admin-suppression":
      if (!confirm("Supprimer définitivement ce pari ?")) return;
      await action(api(`admin/paris/${pariId}/suppression`, { method: "POST" }), "Pari supprimé.");
      break;
  }
});

document.addEventListener("input", (ev) => {
  if (ev.target.closest(".miser")) majGainsPotentiels();
  if (ev.target.closest("#issues-form")) majSommeProbas();
});

$("#ajout-issue").addEventListener("click", () => ajouterLigneIssue());
$("#admin-deconnexion").addEventListener("click", () => action(api("admin/deconnexion", { method: "POST" })));

// Accès direct à l'administration via l'URL …/#admin
if (location.hash === "#admin") onglet = "admin";

reinitialiserFormPari();
rafraichir();
setInterval(() => { if (!document.hidden) rafraichir(); }, RAFRAICHISSEMENT_MS);
document.addEventListener("visibilitychange", () => { if (!document.hidden) rafraichir(); });

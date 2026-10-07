"use strict";

const RAFRAICHISSEMENT_MS = 4000;
const $ = (sel, el = document) => el.querySelector(sel);
const $$ = (sel, el = document) => [...el.querySelectorAll(sel)];
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) =>
  ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const nb = (n) => Math.round(n).toLocaleString("fr-FR");
const pct = (x) => (Math.round(x * 1000) / 10).toLocaleString("fr-FR");
const fmtCote = (c) => c == null ? "—" : "×" + c.toLocaleString("fr-FR", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
// Gain estimé d'une nouvelle mise si plus personne ne mise ensuite (cote amorcée par la banque, cf. api.php).
const gainEstime = (montant, masse, masseIssue, nbIssues, partBanque) =>
  Math.floor(montant * (masse + montant + nbIssues * etat.amorce) / (masseIssue + montant + partBanque));
const fmtDate = (s) => s ? new Date(s).toLocaleString("fr-FR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" }) : "";
const pluriel = (n, mot) => `${n} ${mot}${n > 1 ? "s" : ""}`;

let etat = null;
let onglet = "ouverts";
let ongletAdmin = "paris";
let derniereCle = null; // dernier événement affiché dans le bandeau

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
  const valeurs = {}, coches = {}, ouverts = {};
  $$("[data-k]", el).forEach((i) => {
    if (i.tagName === "DETAILS") ouverts[i.dataset.k] = i.open;
    else if (i.type === "checkbox") coches[i.dataset.k] = i.checked;
    else valeurs[i.dataset.k] = i.value;
  });
  const focus = el.contains(document.activeElement) ? document.activeElement.dataset.k : null;
  el.innerHTML = html;
  el._html = html;
  $$("[data-k]", el).forEach((i) => {
    const k = i.dataset.k;
    if (k in ouverts) i.open = ouverts[k];
    else if (k in coches) i.checked = coches[k];
    else if (k in valeurs && valeurs[k] !== "") i.value = valeurs[k];
  });
  if (focus) $(`[data-k="${focus}"]`, el)?.focus();
}

function rendre() {
  $("#capital").textContent = nb(etat.capital);
  $("#amorce").textContent = nb(etat.amorce);
  $("#onglet-admin").hidden = !etat.admin && onglet !== "admin";
  $("#admin-connexion").hidden = etat.admin;
  $("#admin-contenu").hidden = !etat.admin;
  rendreCompte();
  rendreOnglets();
  rendreBandeau();
  $("#proposer-connexion").hidden = !!(etat.moi || etat.admin);
  $("#form-pari").hidden = !(etat.moi || etat.admin);
  const actifs = etat.paris.filter((p) => p.statut === "ouvert" || p.statut === "suspendu");
  const clos = etat.paris.filter((p) => p.statut === "clos" || p.statut === "annule");
  patch($("#vue-ouverts"), listeParis(actifs, "Aucun pari en cours pour l'instant."));
  patch($("#vue-clos"), listeParis(clos, "Aucun pari clôturé pour l'instant."));
  patch($("#vue-mes-mises"), vueMesMises());
  patch($("#classement"), vueClassement());
  patch($("#fil"), vueFil());
  if (etat.admin && etat.donnees_admin) {
    $$("#onglets-admin button").forEach((b) => b.classList.toggle("actif", b.dataset.ongletAdmin === ongletAdmin));
    $$(".vue-admin").forEach((v) => (v.hidden = v.id !== "admin-" + ongletAdmin));
    patch($("#admin-paris"), vueAdminParis());
    patch($("#admin-joueurs"), vueAdminJoueurs());
    patch($("#admin-reglages"), vueAdminReglages());
  }
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

// ---------------------------------------------------------------------------
// Bandeau « Dernière minute » et fil d'actualité
// ---------------------------------------------------------------------------

function texteEvenement(e) {
  switch (e.type) {
    case "mise":
      return `<b>${esc(e.joueur)}</b> mise ${nb(e.montant)} 🔔 sur « ${esc(e.issue)} » — ${esc(e.pari)}`
        + (e.par_admin ? ` <small>(saisie par l'admin)</small>` : "");
    case "ajustement":
      return (e.montant > 0 ? `🎁 <b>${esc(e.joueur)}</b> reçoit ${nb(e.montant)} 🔔 de l'administration`
                            : `➖ L'administration retire ${nb(-e.montant)} 🔔 à <b>${esc(e.joueur)}</b>`)
        + (e.motif ? ` (${esc(e.motif)})` : "");
    case "clos":
      return `<span class="cloture">🏁 <b>${esc(e.pari)}</b> : « ${esc(e.issue)} ». ` + (e.nb_gagnants
        ? `${pluriel(e.nb_gagnants, "mise gagnante")} rapporte${e.nb_gagnants > 1 ? "nt" : ""} ${nb(e.distribue)} 🔔.`
        : etat.amorce ? "Personne n'avait vu juste : la banque rafle la mise." : "Personne n'avait vu juste : mises remboursées.") + "</span>";
    case "annule":
      return `↩️ <b>${esc(e.pari)}</b> annulé, mises remboursées.`;
    case "pari":
      return e.joueur ? `🆕 <b>${esc(e.joueur)}</b> propose un pari : « ${esc(e.pari)} »` : `🆕 Nouveau pari : « ${esc(e.pari)} »`;
    case "joueur":
      return `👋 <b>${esc(e.joueur)}</b> rejoint la partie`;
  }
  return "";
}

function ilYa(date) {
  const minutes = Math.floor((new Date(etat.maintenant) - new Date(date)) / 60000);
  if (minutes < 1) return "à l'instant";
  if (minutes < 60) return `il y a ${minutes} min`;
  if (minutes < 24 * 60) return `il y a ${Math.floor(minutes / 60)} h`;
  return fmtDate(date);
}

function rendreBandeau() {
  const [derniere, ...precedentes] = etat.fil;
  if (!derniere) return patch($("#bandeau-derniere"), "La partie commence : à vos clochettes !");
  patch($("#bandeau-derniere"), `<span class="quand">${ilYa(derniere.date)}</span> ${texteEvenement(derniere)}`);
  const elements = precedentes.slice(0, 12).map((e) => `<span>${texteEvenement(e)}</span>`).join("");
  // Contenu doublé pour un défilement sans couture ; durée proportionnelle à la longueur.
  patch($("#bandeau-defile"), elements
    ? `<div class="piste" style="animation-duration:${Math.max(30, precedentes.slice(0, 12).length * 7)}s">${elements}${elements}</div>`
    : "");
  const cle = JSON.stringify(derniere);
  if (derniereCle && cle !== derniereCle) {
    const bandeau = $("#bandeau");
    bandeau.classList.remove("nouveau");
    void bandeau.offsetWidth; // relance l'animation
    bandeau.classList.add("nouveau");
  }
  derniereCle = cle;
}

function vueFil() {
  if (!etat.fil.length) return `<li class="vide">Rien pour l'instant.</li>`;
  return etat.fil.map((e) => `<li><time>${fmtDate(e.date)}</time>${texteEvenement(e)}</li>`).join("");
}

// ---------------------------------------------------------------------------
// Paris
// ---------------------------------------------------------------------------

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
  meta.push(`${pluriel(p.nb_joueurs, "joueur")} · cagnotte ${nb(p.total_mise)} 🔔`);
  return `
    <article class="carte pari">
      <div class="pari-entete">
        ${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}
        ${badgeStatut(p)}
        <span class="meta">${meta.join(" · ")}</span>
      </div>
      <h3>${esc(p.titre)}</h3>
      ${p.description ? `<p class="desc">${esc(p.description)}</p>` : ""}
      ${p.auteur ? `<p class="auteur">Proposé par ${esc(p.auteur)}</p>` : ""}
      <div class="issues">${p.issues.map((i) => ligneIssue(p, i)).join("")}</div>
    </article>`;
}

function ligneIssue(p, i) {
  const part = p.total_mise ? i.total_mise / p.total_mise : 0;
  const classe = p.statut === "clos" ? (i.id === p.issue_gagnante_id ? "gagnante" : "perdante") : "";
  const miennes = (etat.mes_mises || []).filter((m) => m.issue_id === i.id);
  const maMise = miennes.reduce((s, m) => s + m.montant, 0);
  let position = "";
  if (maMise) {
    position = p.statut === "clos" || p.statut === "annule"
      ? `Votre mise : ${nb(maMise)} 🔔 → ${nb(miennes.reduce((s, m) => s + (m.gain || 0), 0))} 🔔 récupérées`
      : `Votre mise : ${nb(maMise)} 🔔 · gain si réalisé, à la cote actuelle : ${nb(miennes.reduce((s, m) => s + m.gain_estime, 0))} 🔔`;
  }
  const peutMiser = p.accepte_mises && etat.moi;
  return `
    <div class="issue ${classe}">
      <div class="barre" style="width:${(part * 100).toFixed(1)}%"></div>
      <div>
        <div class="libelle">${esc(i.libelle)}</div>
        <div class="stats">${p.total_mise ? `${pct(part)} % de la cagnotte · ` : ""}${pluriel(i.nb_joueurs, "joueur")} · ${nb(i.total_mise)} 🔔</div>
        ${position ? `<div class="ma-position">${position}</div>` : ""}
      </div>
      <div class="cote" title="${i.cote == null ? "Personne n'a encore misé sur cette issue" : "Cote actuelle : elle évolue à chaque mise"}">${fmtCote(i.cote)}</div>
      ${peutMiser ? `
        <form class="miser" data-issue="${i.id}">
          <input type="number" min="1" step="1" max="${etat.moi.disponible}" placeholder="Mise" data-k="m-${i.id}" data-masse="${p.total_mise}" data-masse-issue="${i.total_mise}" data-nb-issues="${p.issues.length}" data-part="${i.part_banque}">
          <button type="submit" ${etat.moi.disponible < 1 ? "disabled" : ""}>Parier</button>
          <span class="gain" data-gain="${i.id}"></span>
        </form>` : ""}
    </div>`;
}

function majGainsPotentiels() {
  $$(".miser input").forEach((input) => {
    const montant = parseInt(input.value, 10);
    const cible = $(`[data-gain="${input.dataset.k.slice(2)}"]`);
    if (cible) cible.textContent = montant > 0
      ? `→ gain estimé : ${nb(gainEstime(montant, +input.dataset.masse, +input.dataset.masseIssue, +input.dataset.nbIssues, +input.dataset.part))} 🔔 (si personne ne mise après vous)`
      : "";
  });
}

function vueMesMises() {
  if (!etat.moi) return `<p class="vide">Connectez-vous ou créez un compte (en haut à droite) pour parier.</p>`;
  const mises = etat.mes_mises;
  if (!mises.length) return `<p class="vide">Vous n'avez encore rien misé. Rendez-vous dans « Paris en cours » !</p>`;
  const lignes = mises.map((m) => {
    let resultat;
    if (m.statut_pari === "annule") resultat = `<span>remboursé</span>`;
    else if (m.gain === null) resultat = `<span class="aide">en cours · ≈ ${nb(m.gain_estime)} si gagné</span>`;
    else if (m.gain > 0) resultat = `<span class="gain-positif">+${nb(m.gain)}</span>`;
    else resultat = `<span class="gain-nul">perdu</span>`;
    return `<tr>
      <td>${fmtDate(m.cree_le)}</td><td>${esc(m.pari_titre)}</td><td>${esc(m.issue_libelle)}</td>
      <td class="nombre">${nb(m.montant)}</td><td class="nombre">${fmtCote(m.cote)}</td><td class="nombre">${resultat}</td>
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

// ---------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------

const libelleIssue = (p, id) => p.issues.find((i) => i.id === id)?.libelle ?? "?";
const pseudoDe = (id) => etat.classement.find((j) => j.id === id)?.pseudo ?? "?";

/** Formulaire de modification d'un pari (issues ajoutables / retirables seulement s'il est en cours). */
function editionPari(p, actif) {
  const k = `ed-${p.id}`;
  return `
    <details class="edition" data-k="${k}"><summary>Modifier le pari</summary>
      <div class="form-pari">
        <label>Intitulé<input data-k="${k}-titre" maxlength="200" value="${esc(p.titre)}"></label>
        <div class="deux-colonnes">
          <label>Catégorie<input data-k="${k}-cat" maxlength="40" list="categories" value="${esc(p.categorie)}"></label>
          <label>Fin des mises<input type="datetime-local" data-k="${k}-date" value="${p.date_limite || ""}"></label>
        </div>
        <label>Précisions / règle d'arbitrage<textarea data-k="${k}-desc" rows="2" maxlength="500">${esc(p.description)}</textarea></label>
        <fieldset><legend>Issues</legend>
          ${p.issues.map((i) => `<div class="ligne-issue">
            <input data-k="${k}-i-${i.id}" data-issue="${i.id}" maxlength="80" value="${esc(i.libelle)}">
            ${!actif ? "" : i.total_mise ? `<span class="aide">${nb(i.total_mise)} 🔔 misées</span>`
              : `<label class="aide"><input type="checkbox" data-k="${k}-r-${i.id}" data-retirer="${i.id}"> retirer</label>`}
          </div>`).join("")}
          ${actif ? `<input data-k="${k}-nouvelle" maxlength="80" placeholder="Ajouter une issue (facultatif)">` : ""}
        </fieldset>
        <div><button data-action="admin-maj">Enregistrer les modifications</button></div>
      </div>
    </details>`;
}

function listeMisesAdmin(p) {
  const mises = etat.donnees_admin.mises.filter((m) => m.pari_id === p.id);
  if (!mises.length) return "";
  const actif = p.statut === "ouvert" || p.statut === "suspendu";
  return `
    <details data-k="lm-${p.id}"><summary>${pluriel(mises.length, "mise")}</summary>
      <div class="tableau-conteneur"><table>
        <thead><tr><th>Date</th><th>Joueur</th><th>Issue</th><th class="nombre">Mise</th><th class="nombre">${actif ? "" : "Gain"}</th></tr></thead>
        <tbody>${mises.map((m) => `<tr>
          <td>${fmtDate(m.cree_le)}</td><td>${esc(pseudoDe(m.joueur_id))}${m.par_admin ? ` <small class="aide">(admin)</small>` : ""}</td>
          <td>${esc(libelleIssue(p, m.issue_id))}</td><td class="nombre">${nb(m.montant)}</td>
          <td class="nombre">${actif ? `<button class="lien" data-action="admin-suppression-mise" data-mise="${m.id}">supprimer</button>` : nb(m.gain ?? 0)}</td>
        </tr>`).join("")}</tbody>
      </table></div>
    </details>`;
}

function vueAdminParis() {
  const actifs = etat.paris.filter((p) => p.statut === "ouvert" || p.statut === "suspendu");
  const clos = etat.paris.filter((p) => p.statut === "clos" || p.statut === "annule");
  const optionsJoueurs = [...etat.classement].sort((a, b) => a.pseudo.localeCompare(b.pseudo))
    .map((j) => `<option value="${j.id}">${esc(j.pseudo)} (${nb(j.disponible)} 🔔)</option>`).join("");
  const cartes = actifs.map((p) => `
    <article class="carte admin-pari" data-pari="${p.id}">
      <div class="pari-entete">${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}${badgeStatut(p)}
        <span class="meta">${pluriel(p.nb_joueurs, "joueur")} · cagnotte ${nb(p.total_mise)} 🔔</span></div>
      <h3>${esc(p.titre)}</h3>
      ${p.auteur ? `<p class="auteur">Proposé par ${esc(p.auteur)}</p>` : ""}
      <div class="tableau-conteneur"><table>
        <thead><tr><th>Issue</th><th class="nombre">Cote</th><th class="nombre">Misé</th><th class="nombre">Joueurs</th><th class="nombre">Nouvelle cote</th></tr></thead>
        <tbody>${p.issues.map((i) => `<tr>
          <td>${esc(i.libelle)}</td>
          <td class="nombre">${fmtCote(i.cote)}${i.cote_ajustee ? ` <small class="aide" title="Cote fixée par l'administration">✎</small>` : ""}</td>
          <td class="nombre">${nb(i.total_mise)}</td>
          <td class="nombre">${i.nb_joueurs}</td>
          <td class="nombre"><input class="cote-saisie" inputmode="decimal" data-k="c-${i.id}" data-cote-issue="${i.id}" placeholder="ex. 1,8"></td>
        </tr>`).join("")}</tbody>
      </table></div>
      <div class="actions">
        <button class="secondaire" data-action="admin-cotes">Appliquer les nouvelles cotes</button>
        ${p.issues.some((i) => i.cote_ajustee) ? `<button class="lien" data-action="admin-cotes-defaut">Revenir aux cotes calculées</button>` : ""}
      </div>
      ${listeMisesAdmin(p)}
      ${editionPari(p, true)}
      <div class="actions">
        <select data-k="mj-${p.id}"><option value="">— Miser pour… —</option>${optionsJoueurs}</select>
        <select data-k="mi-${p.id}">${p.issues.map((i) => `<option value="${i.id}">${esc(i.libelle)}</option>`).join("")}</select>
        <input type="number" min="1" step="1" placeholder="Mise" data-k="mm-${p.id}" class="petit">
        <button class="secondaire" data-action="admin-miser">Miser</button>
      </div>
      <div class="actions">
        <button class="secondaire" data-action="admin-statut" data-statut="${p.statut === "ouvert" ? "suspendu" : "ouvert"}">
          ${p.statut === "ouvert" ? "Suspendre les mises" : "Rouvrir les mises"}</button>
        <select data-k="w-${p.id}"><option value="">— Issue réalisée —</option>
          ${p.issues.map((i) => `<option value="${i.id}">${esc(i.libelle)}</option>`).join("")}</select>
        <button data-action="admin-cloture">Clôturer et payer</button>
        <button class="danger" data-action="admin-annulation">Annuler (rembourser)</button>
        ${p.total_mise === 0 ? `<button class="lien" data-action="admin-suppression">Supprimer</button>` : ""}
      </div>
    </article>`);
  const cartesClos = clos.map((p) => `
    <article class="carte admin-pari" data-pari="${p.id}">
      <div class="pari-entete">${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}${badgeStatut(p)}
        <span class="meta">${p.clos_le ? `le ${fmtDate(p.clos_le)} · ` : ""}cagnotte ${nb(p.total_mise)} 🔔</span></div>
      <h3>${esc(p.titre)}</h3>
      <p>${p.statut === "annule" ? "Annulé, mises remboursées." : `Issue réalisée : « ${esc(libelleIssue(p, p.issue_gagnante_id))} »`}</p>
      ${listeMisesAdmin(p)}
      ${editionPari(p, false)}
      <div class="actions">
        <button class="secondaire" data-action="admin-reouverture">Revenir sur ${p.statut === "annule" ? "l'annulation" : "la clôture"}</button>
      </div>
    </article>`);
  return (cartes.join("") || `<p class="vide">Aucun pari en cours.</p>`)
    + (cartesClos.length ? `<h2 class="titre-section">Paris clôturés</h2>${cartesClos.join("")}` : "");
}

function vueAdminJoueurs() {
  if (!etat.classement.length) return `<p class="vide">Aucun joueur inscrit.</p>`;
  const joueurs = [...etat.classement].sort((a, b) => a.pseudo.localeCompare(b.pseudo));
  return joueurs.map((j) => {
    const ajustements = etat.donnees_admin.ajustements.filter((a) => a.joueur_id === j.id);
    const nbMises = etat.donnees_admin.mises.filter((m) => m.joueur_id === j.id).length;
    return `
    <details class="carte admin-joueur" data-joueur="${j.id}" data-k="j-${j.id}">
      <summary><b>${esc(j.pseudo)}</b>
        <span class="meta">${j.rang}<sup>e</sup> · disponible ${nb(j.disponible)} · en jeu ${nb(j.en_jeu)} · total ${nb(j.total)} 🔔 · ${pluriel(nbMises, "mise")}</span></summary>
      <div class="actions">
        <label class="aide">Pseudo <input data-k="jp-${j.id}" maxlength="30" value="${esc(j.pseudo)}"></label>
        <label class="aide">Nouveau code <input data-k="jc-${j.id}" maxlength="64" placeholder="inchangé" autocomplete="off"></label>
        <button class="secondaire" data-action="admin-joueur-maj">Enregistrer</button>
      </div>
      <div class="actions">
        <label class="aide">Clochettes <input type="number" step="1" data-k="ja-${j.id}" placeholder="+100 ou -50" class="petit"></label>
        <label class="aide">Motif <input data-k="jm-${j.id}" maxlength="200" placeholder="facultatif, visible de tous"></label>
        <button class="secondaire" data-action="admin-ajustement">Créditer / débiter</button>
      </div>
      ${ajustements.length ? `<ul class="aide">${ajustements.map((a) =>
        `<li>${fmtDate(a.cree_le)} : ${a.montant > 0 ? "+" : ""}${nb(a.montant)} 🔔${a.motif ? ` (${esc(a.motif)})` : ""}</li>`).join("")}</ul>` : ""}
      <div class="actions"><button class="danger" data-action="admin-joueur-suppression">Supprimer le joueur</button></div>
    </details>`;
  }).join("");
}

function vueAdminReglages() {
  const d = etat.donnees_admin;
  return `
    <div class="carte">
      <h2>Réglages du jeu</h2>
      <div class="actions">
        <label class="aide">Capital de départ <input type="number" min="0" step="1" data-k="r-capital" value="${etat.capital}" class="petit"></label>
        <label class="aide">Amorce de la banque, par issue <input type="number" min="0" step="1" data-k="r-amorce" value="${etat.amorce}" class="petit"></label>
        <button data-action="admin-reglages">Enregistrer</button>
        <button class="lien" data-action="admin-reglages-defaut">Revenir aux valeurs par défaut (${nb(d.capital_defaut)} / ${nb(d.amorce_defaut)})</button>
      </div>
      <ul class="aide">
        <li>Le capital s'applique rétroactivement : changer 1 000 en 1 500 ajoute 500 🔔 à chaque joueur.</li>
        <li>L'amorce change immédiatement les cotes de tous les paris en cours (pas ceux déjà clôturés).
          Plus elle est haute, plus les cotes sont stables ; 0 = pari mutuel pur.</li>
        <li>Ces valeurs priment sur les variables GitHub <code>PLF_CAPITAL</code> et <code>PLF_AMORCE</code>.</li>
      </ul>
    </div>`;
}

function ajouterLigneIssue(libelle = "") {
  const ligne = document.createElement("div");
  ligne.className = "ligne-issue";
  ligne.innerHTML = `<input name="libelle" maxlength="80" placeholder="Libellé de l'issue" value="${esc(libelle)}">
    <button type="button" class="lien" data-action="retirer-issue">retirer</button>`;
  $("#issues-form").append(ligne);
}

function reinitialiserFormPari() {
  $("#form-pari").reset();
  $("#issues-form").innerHTML = "";
  ajouterLigneIssue("Oui");
  ajouterLigneIssue("Non");
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
    const issues = $$('.ligne-issue [name="libelle"]', form).map((i) => i.value);
    const body = { titre: form.titre.value, categorie: form.categorie.value, description: form.description.value, date_limite: form.date_limite.value, issues };
    if (await action(api("paris", { body }), "Pari publié : à vous de miser !")) {
      reinitialiserFormPari();
      onglet = "ouverts";
      rendre();
    }
  }
});

document.addEventListener("click", async (ev) => {
  const bouton = ev.target.closest("[data-onglet], [data-onglet-admin], [data-action]");
  if (!bouton) return;
  if (bouton.dataset.onglet) {
    onglet = bouton.dataset.onglet;
    return rendre();
  }
  if (bouton.dataset.ongletAdmin) {
    ongletAdmin = bouton.dataset.ongletAdmin;
    return rendre();
  }
  const carte = bouton.closest("[data-pari]");
  const pariId = carte?.dataset.pari;
  const fiche = bouton.closest("[data-joueur]");
  const joueurId = fiche?.dataset.joueur;
  const champ = (k, el = document) => $(`[data-k="${k}"]`, el);
  switch (bouton.dataset.action) {
    case "deconnexion":
      await action(api("deconnexion", { method: "POST" }));
      break;
    case "retirer-issue":
      bouton.closest(".ligne-issue").remove();
      break;
    case "admin-maj": {
      const k = `ed-${pariId}`;
      const body = {
        titre: champ(`${k}-titre`, carte).value, categorie: champ(`${k}-cat`, carte).value,
        description: champ(`${k}-desc`, carte).value, date_limite: champ(`${k}-date`, carte).value,
        issues: Object.fromEntries($$(`.edition [data-issue]`, carte).map((i) => [i.dataset.issue, i.value])),
        retirer: $$("[data-retirer]", carte).filter((c) => c.checked).map((c) => Number(c.dataset.retirer)),
        nouvelles: champ(`${k}-nouvelle`, carte) ? [champ(`${k}-nouvelle`, carte).value] : [],
      };
      if (await action(api(`admin/paris/${pariId}/maj`, { body }), "Pari modifié.")) {
        const nouvelle = champ(`${k}-nouvelle`, carte);
        if (nouvelle) nouvelle.value = "";
      }
      break;
    }
    case "admin-cotes": {
      const champs = $$("[data-cote-issue]", carte).filter((c) => c.value.trim() !== "");
      if (!champs.length) return toast("Saisissez au moins une nouvelle cote.", "erreur");
      const cotes = Object.fromEntries(champs.map((c) => [c.dataset.coteIssue, c.value]));
      if (await action(api(`admin/paris/${pariId}/cotes`, { body: { cotes } }), "Cotes mises à jour.")) champs.forEach((c) => (c.value = ""));
      break;
    }
    case "admin-cotes-defaut": {
      const cotes = Object.fromEntries($$("[data-cote-issue]", carte).map((c) => [c.dataset.coteIssue, ""]));
      await action(api(`admin/paris/${pariId}/cotes`, { body: { cotes } }), "Cotes recalculées à partir des mises.");
      break;
    }
    case "admin-miser": {
      const joueur = champ(`mj-${pariId}`, carte), issue = champ(`mi-${pariId}`, carte), montant = champ(`mm-${pariId}`, carte);
      if (!joueur.value) return toast("Choisissez le joueur.", "erreur");
      const body = { joueur_id: Number(joueur.value), issue_id: Number(issue.value), montant: parseInt(montant.value, 10) };
      if (await action(api("admin/mises", { body }), `Mise de ${nb(body.montant)} 🔔 enregistrée pour ${pseudoDe(body.joueur_id)}.`)) montant.value = "";
      break;
    }
    case "admin-suppression-mise":
      if (!confirm("Supprimer cette mise ? Le joueur la récupère.")) return;
      await action(api(`admin/mises/${bouton.dataset.mise}/suppression`, { method: "POST" }), "Mise supprimée et remboursée.");
      break;
    case "admin-reouverture": {
      if (!confirm("Revenir sur ce résultat ? Les gains versés sont repris et le pari repasse en « suspendu ». "
        + "Un joueur qui a déjà remisé ses gains peut se retrouver avec un solde négatif.")) return;
      try {
        const r = await api(`admin/paris/${pariId}/reouverture`, { method: "POST" });
        toast(r.soldes_negatifs.length ? `Pari rouvert. Solde négatif pour : ${r.soldes_negatifs.join(", ")}.` : "Pari rouvert (suspendu).", "succes");
        await rafraichir();
      } catch (e) {
        toast(e.message, "erreur");
      }
      break;
    }
    case "admin-joueur-maj": {
      const code = champ(`jc-${joueurId}`, fiche);
      if (await action(api(`admin/joueurs/${joueurId}/maj`, { body: { pseudo: champ(`jp-${joueurId}`, fiche).value, pin: code.value } }), "Joueur mis à jour.")) code.value = "";
      break;
    }
    case "admin-ajustement": {
      const montant = champ(`ja-${joueurId}`, fiche), motif = champ(`jm-${joueurId}`, fiche);
      const body = { montant: parseInt(montant.value, 10), motif: motif.value };
      if (await action(api(`admin/joueurs/${joueurId}/ajustement`, { body }), body.montant > 0 ? "Clochettes créditées." : "Clochettes débitées.")) {
        montant.value = motif.value = "";
      }
      break;
    }
    case "admin-joueur-suppression":
      if (!confirm(`Supprimer définitivement ${pseudoDe(Number(joueurId))} et toutes ses mises ?`)) return;
      await action(api(`admin/joueurs/${joueurId}/suppression`, { method: "POST" }), "Joueur supprimé.");
      break;
    case "admin-reglages":
      await action(api("admin/reglages", { body: { capital: champ("r-capital").value, amorce: champ("r-amorce").value } }), "Réglages enregistrés.");
      break;
    case "admin-reglages-defaut":
      champ("r-capital").value = champ("r-amorce").value = "";
      await action(api("admin/reglages", { body: { capital: "", amorce: "" } }), "Valeurs par défaut rétablies.");
      break;
    case "admin-statut":
      await action(api(`admin/paris/${pariId}/statut`, { body: { statut: bouton.dataset.statut } }));
      break;
    case "admin-cloture": {
      const select = $(`[data-k="w-${pariId}"]`, carte);
      if (!select.value) return toast("Choisissez d'abord l'issue réalisée.", "erreur");
      const libelle = select.selectedOptions[0].textContent;
      if (!confirm(`Clôturer ce pari avec l'issue « ${libelle} » ? La cagnotte sera partagée immédiatement, c'est définitif.`)) return;
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
});

$("#ajout-issue").addEventListener("click", () => ajouterLigneIssue());
$("#admin-deconnexion").addEventListener("click", () => action(api("admin/deconnexion", { method: "POST" })));

// Accès à l'administration via l'URL …/#admin, au chargement ou en cours de visite
if (location.hash === "#admin") onglet = "admin";
window.addEventListener("hashchange", () => {
  if (location.hash !== "#admin") return;
  onglet = "admin";
  if (etat) rendre();
});

reinitialiserFormPari();
rafraichir();
setInterval(() => { if (!document.hidden) rafraichir(); }, RAFRAICHISSEMENT_MS);
document.addEventListener("visibilitychange", () => { if (!document.hidden) rafraichir(); });

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
const fmtNombre = (v) => v == null ? "—" : v.toLocaleString("fr-FR", { maximumFractionDigits: 3 });
const avecUnite = (v, unite) => fmtNombre(v) + (unite ? " " + unite : "");
const lienPari = (id) => location.origin + location.pathname + "#pari-" + id;

let etat = null;
let onglet = "accueil";
let ongletAdmin = "paris";
let derniereCle = null; // dernier événement affiché dans le bandeau
let modeClassement = "joueurs";
let decalageHorloge = 0; // heure du serveur − heure du navigateur (mesurée à chaque état reçu)
let historiqueJoueurs = null, chargementStats = false; // courbes des joueurs (onglet Statistiques)
const historiquesCotes = {}; // courbes des cotes, par pari

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
    const reponse = await promesse;
    const message = typeof messageSucces === "function" ? messageSucces(reponse) : messageSucces;
    if (message) toast(message, "succes");
    await rafraichir();
    return true;
  } catch (e) {
    toast(e.message, "erreur");
    return false;
  }
}

const avecBonus = (r) => (r.bonus_question ? ` ⭐ +${nb(r.bonus_question)} 🪙 de bonus pour avoir répondu à la question du jour !` : "");

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
    // Le serveur ne renvoie l'état complet que s'il a changé depuis la version « v » déjà reçue.
    const r = await api("etat" + (etat ? "&v=" + etat.v : ""));
    $(".direct").classList.remove("hors-ligne");
    if (r.inchange) return;
    const premier = !etat;
    etat = r;
    decalageHorloge = new Date(r.maintenant) - Date.now(); // pour les comptes à rebours
    rendre();
    if (premier) suivreAncre();
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
  $("#onglet-profil").hidden = !etat.moi;
  if (etat.moi && onglet === "inscription") onglet = "profil"; // inscription réussie
  $("#admin-connexion").hidden = etat.admin;
  $("#admin-contenu").hidden = !etat.admin;
  rendreCompte();
  rendreOnglets();
  rendreBandeau();
  $("#proposer-connexion").hidden = !!(etat.moi || etat.admin);
  $("#form-pari").hidden = !(etat.moi || etat.admin);
  remplirSelect($("#form-pari-etape"), `<option value="">— Aucune —</option>` + optionsEtapes());
  remplirSelect($("#stats-comparer"), `<option value="">— personne —</option>` + [...etat.classement]
    .sort((a, b) => a.pseudo.localeCompare(b.pseudo)).map((j) => `<option value="${j.id}">${esc(j.pseudo)}</option>`).join(""));
  $("#bascule-classement").hidden = !etat.equipes.length;
  if (!etat.equipes.length) modeClassement = "joueurs";
  $$("#bascule-classement button").forEach((b) => b.classList.toggle("actif", b.dataset.classement === modeClassement));
  const actifs = etat.paris.filter((p) => p.statut === "ouvert" || p.statut === "suspendu");
  const clos = etat.paris.filter((p) => p.statut === "clos" || p.statut === "annule");
  patch($("#vue-accueil"), vueAccueil());
  patch($("#vue-ouverts"), listeParis(actifs, "Aucun pari en cours pour l'instant."));
  patch($("#vue-clos"), listeParis(clos, "Aucun pari clôturé pour l'instant."));
  patch($("#vue-mes-mises"), vueMesMises());
  patch($("#vue-profil"), vueProfil());
  patch($("#vue-inscription"), vueInscription());
  patch($("#vue-calendrier"), vueCalendrier());
  patch($("#classement"), vueClassement());
  patch($("#fil"), vueFil());
  if (etat.admin && etat.donnees_admin) {
    $$("#onglets-admin button").forEach((b) => b.classList.toggle("actif", b.dataset.ongletAdmin === ongletAdmin));
    $$(".vue-admin").forEach((v) => (v.hidden = v.id !== "admin-" + ongletAdmin));
    patch($("#admin-paris"), vueAdminParis());
    patch($("#admin-joueurs"), vueAdminJoueurs());
    patch($("#admin-calendrier"), vueAdminCalendrier());
    patch($("#admin-reglages"), vueAdminReglages());
  }
  $("#categories").innerHTML = [...new Set(etat.paris.map((p) => p.categorie).filter(Boolean))]
    .map((c) => `<option value="${esc(c)}">`).join("");
  $("#liste-equipes").innerHTML = etat.equipes.map((e) => `<option value="${esc(e.nom)}">`).join("");
  majComptesARebours();
  majPariDuJour();
  animerCompteurs();
  majFicheProfil();
  majGainsPotentiels();
  majCourbes();
  majStats();
}

/** Remplace les options d'une liste déroulante sans perdre la sélection en cours. */
function remplirSelect(select, html) {
  if (select._html === html) return;
  const valeur = select.value;
  select.innerHTML = html;
  select._html = html;
  if ([...select.options].some((o) => o.value === valeur)) select.value = valeur;
}

const optionsEtapes = (choisie = null) => etat.etapes.map((e) =>
  `<option value="${e.id}"${e.id === choisie ? " selected" : ""}>${fmtJour(e.date)} — ${esc(e.titre)}</option>`).join("");
const fmtJour = (d) => new Date(d + "T12:00").toLocaleDateString("fr-FR", { weekday: "short", day: "numeric", month: "short" });
const optionsEquipes = (choisie = null, vide = "— Sans équipe —") => `<option value="">${vide}</option>` + etat.equipes.map((e) =>
  `<option value="${e.id}"${e.id === choisie ? " selected" : ""}>${esc(e.nom)}</option>`).join("");

function rendreCompte() {
  const moi = etat.moi;
  const b = etat.bonus;
  const bonus = !moi || !b.montant ? "" : b.disponible
    ? `<button class="bonus-pret" data-action="bonus" title="Bonus quotidien : ${nb(b.montant)} deniers publics toutes les 24 heures">🎁 +${nb(b.montant)}</button>`
    : `<span class="bonus-attente" title="Prochain bonus quotidien de ${nb(b.montant)} 🪙">🎁 <span data-fin="${esc(b.prochain)}" data-sorte="bonus"></span></span>`;
  const html = moi
    ? `${bonus}<div class="solde">
         <div class="bloc"><small>Disponible</small><b>${nb(moi.disponible)} 🪙</b></div>
         <div class="bloc"><small>En jeu</small><b>${nb(moi.en_jeu)}</b></div>
         <div class="bloc"><small>Rang</small><b>${moi.rang}<sup>${moi.rang === 1 ? "er" : "e"}</sup></b></div>
       </div>
       <div><button class="lien pseudo" data-onglet="profil" title="Mon profil"><b>${esc(moi.pseudo)}</b> ${icones(moi.trophees)}</button>
         <br><button class="lien" data-action="deconnexion">Déconnexion</button></div>`
    : `<form id="form-joueur">
         <input data-k="pseudo" name="pseudo" placeholder="Pseudo" autocomplete="username" required>
         <input data-k="pin" name="pin" type="password" placeholder="Code secret" autocomplete="current-password" required>
         <button type="submit">Se connecter</button>
         <button type="button" class="secondaire" data-onglet="inscription">Créer un compte</button>
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
      return `<b>${esc(e.joueur)}</b> mise ${nb(e.montant)} 🪙 ${e.issue == null ? "sur une estimation" : `sur « ${esc(e.issue)} »`} — ${esc(e.pari)}`
        + (e.par_admin ? ` <small>(saisie par l'admin)</small>` : "");
    case "ajustement":
      return (e.montant > 0 ? `🎁 <b>${esc(e.joueur)}</b> reçoit ${nb(e.montant)} 🪙 de l'administration`
                            : `➖ L'administration retire ${nb(-e.montant)} 🪙 à <b>${esc(e.joueur)}</b>`)
        + (e.motif ? ` (${esc(e.motif)})` : "");
    case "clos":
      return `<span class="cloture">🏁 <b>${esc(e.pari)}</b> : ` + (e.valeur != null
        ? `valeur réelle ${esc(avecUnite(e.valeur, e.unite))}. ` + (e.nb_gagnants
          ? `${e.nb_gagnants > 1 ? `${e.nb_gagnants} estimations les plus proches se partagent` : "L'estimation la plus proche empoche"} ${nb(e.distribue)} 🪙.`
          : "Aucune estimation.")
        : `« ${esc(e.issue)} ». ` + (e.nb_gagnants
          ? `${pluriel(e.nb_gagnants, "mise gagnante")} rapporte${e.nb_gagnants > 1 ? "nt" : ""} ${nb(e.distribue)} 🪙.`
          : etat.amorce ? "Personne n'avait vu juste : la banque rafle la mise." : "Personne n'avait vu juste : mises remboursées."))
        + (e.nb_tardives ? ` ${pluriel(e.nb_tardives, "mise tardive")} remboursée${e.nb_tardives > 1 ? "s" : ""}.` : "") + "</span>";
    case "annule":
      return `↩️ <b>${esc(e.pari)}</b> annulé, mises remboursées.`;
    case "bonus":
      return e.sorte === "question"
        ? `⭐ <b>${esc(e.joueur)}</b> répond à la question du jour${e.pari ? ` « ${esc(e.pari)} »` : ""} (+${nb(e.montant)} 🪙)`
        : `🎁 <b>${esc(e.joueur)}</b> récupère son bonus quotidien (+${nb(e.montant)} 🪙)`;
    case "depeche":
      return `<span class="evt-depeche">📰 <b>Dépêche</b> : ${esc(e.texte)}</span>`;
    case "mouvement":
      return `<span class="evt-mouvement">${e.variation > 0 ? "📈" : "📉"} <b>${esc(e.pari)}</b> : « ${esc(e.issue)} » vient de
        ${e.variation > 0 ? "prendre" : "perdre"} <b>${Math.abs(e.variation)} pts</b>
        ${e.reference === "ouverture" ? "depuis l'ouverture" : e.reference ? `depuis « ${esc(e.reference)} »` : "en 24 h"} (${pourcent(e.probabilite)})</span>`;
    case "flash":
      return `<span class="evt-flash">⚡ <b>Pari flash</b> : « ${esc(e.pari)} » — mises jusqu'à ${esc(fmtHeure(e.date_limite))}</span>`;
    case "commentaire":
      return `💬 <b>${esc(e.joueur)}</b> sur « ${esc(e.pari)} » : ${esc(e.texte.length > 90 ? e.texte.slice(0, 90) + "…" : e.texte)}`;
    case "trophee":
      return `🏆 <b>${esc(e.joueur)}</b> obtient le trophée ${e.icone} <b>${esc(e.trophee)}</b>`;
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
  if (!derniere) return patch($("#bandeau-derniere"), "La partie commence : à vos deniers publics !");
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

/** Minuteur de fin des mises d'un pari ouvert qui a une date limite (les paris flash ont leur propre bandeau). */
const minuteur = (p) => p.accepte_mises && p.date_limite && !p.flash
  ? `<span class="minuteur" title="Fin des mises le ${esc(fmtDate(p.date_limite))}">⏳ <span data-fin="${esc(p.date_limite)}" data-sorte="pari"></span></span>` : "";

function listeParis(paris, messageVide) {
  if (!paris.length) return `<p class="vide">${messageVide}</p>`;
  const enTete = (p) => (p.flash && p.accepte_mises ? 0 : 1);
  paris = [...paris].sort((a, b) => enTete(a) - enTete(b)); // tri stable : l'ordre du serveur est conservé
  return `<div class="liste-paris">${paris.map(cartePari).join("")}</div>`;
}

const fmtHeure = (s) => s ? new Date(s).toLocaleTimeString("fr-FR", { hour: "2-digit", minute: "2-digit" }) : "";
const icones = (codes) => (codes || []).map((c) => `<span class="trophee" title="${esc(etat.trophees[c].nom)} : ${esc(etat.trophees[c].condition)}">${etat.trophees[c].icone}</span>`).join("");

/** Commentaires d'un pari (« exposé des motifs ») et formulaire pour en ajouter un. */
function blocCommentaires(p) {
  const liste = p.commentaires.map((c) => `
    <li><button class="lien-joueur" data-fiche="${c.joueur_id}"><b>${esc(c.joueur)}</b></button> <time>${fmtDate(c.date)}</time>
      ${etat.admin || c.joueur_id === etat.moi?.id ? `<button class="lien" data-action="supprimer-commentaire" data-commentaire="${c.id}" title="Supprimer">✕</button>` : ""}
      <div>${esc(c.texte)}</div></li>`).join("");
  return `
    <details class="commentaires" data-k="cm-${p.id}">
      <summary>💬 Exposé des motifs${p.commentaires.length ? ` (${p.commentaires.length})` : ""}</summary>
      ${liste ? `<ul>${liste}</ul>` : `<p class="aide">Aucun commentaire. Justifiez votre pari !</p>`}
      ${etat.moi ? `<form class="form-commentaire" data-pari-commentaire="${p.id}">
        <input name="texte" data-k="ct-${p.id}" maxlength="280" placeholder="Votre commentaire (280 caractères)" required>
        <button type="submit" class="secondaire">Publier</button></form>` : `<p class="aide">Connectez-vous pour commenter.</p>`}
    </details>`;
}

function cartePari(p) {
  const meta = [];
  if (p.date_limite && p.statut === "ouvert" && !p.accepte_mises) meta.push(`Mises closes le ${fmtDate(p.date_limite)}`);
  if (p.clos_le) meta.push(`Clôturé le ${fmtDate(p.clos_le)}`);
  meta.push(`${pluriel(p.nb_joueurs, "joueur")} · cagnotte ${nb(p.total_mise)} 🪙`);
  const etape = etat.etapes.find((e) => e.id === p.etape_id);
  const flashOuvert = p.flash && p.accepte_mises;
  return `
    <article class="carte pari${flashOuvert ? " flash" : ""}" id="pari-${p.id}">
      ${flashOuvert ? `<div class="bandeau-flash">⚡ Pari flash · mises closes dans <b data-fin="${esc(p.date_limite)}"></b></div>` : ""}
      <div class="pari-entete">
        ${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}
        ${p.type === "estimation" ? `<span class="badge estimation">Chiffre</span>` : ""}
        ${badgeStatut(p)}
        ${minuteur(p)}
        ${etape ? `<button class="badge etape" data-onglet="calendrier" title="Voir le calendrier">📅 ${fmtJour(etape.date)} · ${esc(etape.titre)}</button>` : ""}
        <span class="meta">${meta.join(" · ")}</span>
        <button class="lien lien-pari" data-action="copier-lien" data-pari-lien="${p.id}" title="Copier le lien vers ce pari">🔗 Lien</button>
      </div>
      <div class="pari-titre">
        <div><h3>${esc(p.titre)}</h3>
          ${p.description ? `<p class="desc">${esc(p.description)}</p>` : ""}
          ${p.auteur ? `<p class="auteur">Proposé par ${esc(p.auteur)}</p>` : ""}</div>
        ${marche(p) ? miniCourbe(marche(p), p) : ""}
      </div>
      ${p.type === "estimation" ? blocEstimation(p) : `<div class="issues">${p.issues.map((i) => ligneIssue(p, i)).join("")}</div>`}
      ${p.nb_tardives ? `<p class="aide">⏱ ${pluriel(p.nb_tardives, "mise placée")} après le résultat (connu le ${fmtDate(p.realise_le)}) : remboursée${p.nb_tardives > 1 ? "s" : ""}, hors cagnotte.</p>` : ""}
      ${p.type === "choix" ? `<details class="courbe-cotes" data-k="hc-${p.id}" data-courbe-pari="${p.id}">
        <summary>📈 Évolution des probabilités</summary><div class="graphique"></div></details>` : ""}
      ${blocCommentaires(p)}
    </article>`;
}

/** Pari sur un chiffre : estimations secrètes tant que le pari est en cours, classement à la clôture. */
function blocEstimation(p) {
  const issue = p.issues[0];
  const mienne = (etat.mes_mises || []).find((m) => m.pari_id === p.id);
  const lignes = [];
  if (p.statut === "clos") {
    lignes.push(`<p class="valeur-reelle">Valeur réelle : <b>${esc(avecUnite(p.valeur_reelle, p.unite))}</b></p>`);
    if (p.estimations.length) lignes.push(`<div class="tableau-conteneur"><table>
      <thead><tr><th></th><th>Joueur</th><th class="nombre">Estimation</th><th class="nombre">Écart</th><th class="nombre">Mise</th><th class="nombre">Gain</th></tr></thead>
      <tbody>${p.estimations.map((e, n) => `<tr class="${e.gain > 0 && !e.tardive ? "gagnante" : ""}">
        <td class="rang">${e.tardive ? "⏱" : n + 1}</td><td>${esc(e.joueur)}</td>
        <td class="nombre">${esc(fmtNombre(e.estimation))}</td>
        <td class="nombre">${esc(fmtNombre(Math.abs(e.estimation - p.valeur_reelle)))}</td>
        <td class="nombre">${nb(e.montant)}</td>
        <td class="nombre">${e.tardive ? "remboursée" : e.gain > 0 ? `<span class="gain-positif">${nb(e.gain)}</span>` : "0"}</td>
      </tr>`).join("")}</tbody></table></div>`);
  } else {
    lignes.push(`<p class="stats">${pluriel(p.nb_joueurs, "estimation")} déposée${p.nb_joueurs > 1 ? "s" : ""} (secrètes jusqu'à la clôture)
      · à gagner : ${nb(p.total_mise)} 🪙 + ${nb(etat.amorce)} 🪙 de la banque</p>`);
  }
  if (mienne) {
    lignes.push(`<p class="ma-position">Votre estimation : ${esc(avecUnite(mienne.estimation, p.unite))} · mise ${nb(mienne.montant)} 🪙`
      + (mienne.gain != null ? ` → ${mienne.tardive ? "remboursée (mise tardive)" : `${nb(mienne.gain)} 🪙 récupérés`}` : "") + "</p>");
  } else if (p.accepte_mises && etat.moi) {
    lignes.push(`<form class="miser miser-estimation" data-issue="${issue.id}">
      <input inputmode="decimal" placeholder="Votre estimation" data-k="e-${p.id}" name="estimation" required>
      ${p.unite ? `<span class="aide">${esc(p.unite)}</span>` : ""}
      <input type="number" min="1" step="1" max="${etat.moi.disponible}" placeholder="Mise" data-k="em-${p.id}" name="montant" required>
      <button type="submit" ${etat.moi.disponible < 1 ? "disabled" : ""}>Parier</button>
      <span class="aide">Une seule estimation par joueur.</span>
    </form>`);
  }
  return `<div class="bloc-estimation">${lignes.join("")}</div>`;
}

/** Tendance d'un pari à choix en cours (voir tendances() côté serveur), ou null. */
const marche = (p) => etat.tendances.find((t) => t.pari_id === p.id) || null;

/** Probabilité implicite et variations de chaque issue : {id: {probabilite, variation, variation_depeche}}. */
function probasDe(p) {
  const t = marche(p);
  if (t) return Object.fromEntries(t.issues.map((i) => [i.id, i]));
  const inverses = p.issues.map((i) => (i.cote ? 1 / i.cote : 0)), somme = inverses.reduce((a, b) => a + b, 0);
  return Object.fromEntries(p.issues.map((i, k) => [i.id, { probabilite: somme ? inverses[k] / somme : null, variation: null }]));
}

function ligneIssue(p, i, prefixe = "m") {
  const part = p.total_mise ? i.total_mise / p.total_mise : 0;
  const classe = p.statut === "clos" ? (i.id === p.issue_gagnante_id ? "gagnante" : "perdante") : "";
  const miennes = (etat.mes_mises || []).filter((m) => m.issue_id === i.id);
  const maMise = miennes.reduce((s, m) => s + m.montant, 0);
  let position = "";
  if (maMise) {
    position = p.statut === "clos" || p.statut === "annule"
      ? `Votre mise : ${nb(maMise)} 🪙 → ${nb(miennes.reduce((s, m) => s + (m.gain || 0), 0))} 🪙 récupérés`
      : `Votre mise : ${nb(maMise)} 🪙 · gain si réalisé, à la cote actuelle : ${nb(miennes.reduce((s, m) => s + m.gain_estime, 0))} 🪙`;
  }
  const peutMiser = p.accepte_mises && etat.moi;
  const pr = probasDe(p)[i.id];
  return `
    <div class="issue ${classe}">
      <div class="barre" style="width:${(part * 100).toFixed(1)}%"></div>
      <div>
        <div class="libelle">${esc(i.libelle)}</div>
        <div class="stats">${p.total_mise ? `${pct(part)} % de la cagnotte · ` : ""}${pluriel(i.nb_joueurs, "joueur")} · ${nb(i.total_mise)} 🪙</div>
        ${position ? `<div class="ma-position">${position}</div>` : ""}
      </div>
      <div class="proba" title="Probabilité implicite de la cote ${fmtCote(i.cote)} (elle évolue à chaque mise)">
        <b>${pr?.probabilite == null ? "—" : pourcent(pr.probabilite)}</b>
        ${p.statut === "ouvert" || p.statut === "suspendu" ? variationCourte(pr, marche(p)) : ""}
        <small>${p.statut === "clos" ? "cote finale" : "rapporte"} ${fmtCote(i.cote)}</small>
      </div>
      ${peutMiser ? `
        <form class="miser" data-issue="${i.id}">
          <input type="number" min="1" step="1" max="${etat.moi.disponible}" placeholder="Mise" data-k="${prefixe}-${i.id}" data-masse="${p.total_mise}" data-masse-issue="${i.total_mise}" data-nb-issues="${p.issues.length}" data-part="${i.part_banque}">
          <button type="submit" ${etat.moi.disponible < 1 ? "disabled" : ""}>Parier</button>
          <span class="gain"></span>
        </form>` : ""}
    </div>`;
}

function majGainsPotentiels() {
  $$(".miser:not(.miser-estimation) input").forEach((input) => {
    const montant = parseInt(input.value, 10);
    const cible = $(".gain", input.closest("form"));
    if (cible) cible.textContent = montant > 0
      ? `→ gain estimé : ${nb(gainEstime(montant, +input.dataset.masse, +input.dataset.masseIssue, +input.dataset.nbIssues, +input.dataset.part))} 🪙 (si personne ne mise après vous)`
      : "";
  });
}

function vueMesMises() {
  if (!etat.moi) return `<p class="vide">Connectez-vous ou créez un compte (en haut à droite) pour parier.</p>`;
  const equipe = "";
  const mises = etat.mes_mises;
  if (!mises.length) return equipe + `<p class="vide">Vous n'avez encore rien misé. Rendez-vous dans « Paris en cours » !</p>`;
  const lignes = mises.map((m) => {
    let resultat;
    if (m.statut_pari === "annule") resultat = `<span>remboursé</span>`;
    else if (m.tardive) resultat = `<span title="Mise placée après que le résultat était connu">remboursée (tardive)</span>`;
    else if (m.gain === null) resultat = m.gain_estime == null ? `<span class="aide">en cours</span>`
      : `<span class="aide">en cours · ≈ ${nb(m.gain_estime)} si gagné</span>`;
    else if (m.gain > 0) resultat = `<span class="gain-positif">+${nb(m.gain)}</span>`;
    else resultat = `<span class="gain-nul">perdu</span>`;
    const issue = m.type_pari === "estimation" ? `Estimation : ${esc(fmtNombre(m.estimation))}` : esc(m.issue_libelle);
    return `<tr>
      <td>${fmtDate(m.cree_le)}</td><td><a href="#pari-${m.pari_id}">${esc(m.pari_titre)}</a></td><td>${issue}</td>
      <td class="nombre">${nb(m.montant)}</td><td class="nombre">${fmtCote(m.cote)}</td><td class="nombre">${resultat}</td>
    </tr>`;
  }).join("");
  return equipe + `<div class="carte tableau-conteneur"><table>
    <thead><tr><th>Date</th><th>Pari</th><th>Issue</th><th class="nombre">Mise</th><th class="nombre">Cote</th><th class="nombre">Résultat</th></tr></thead>
    <tbody>${lignes}</tbody></table></div>`;
}

function vueClassement() {
  const medailles = { 1: "🥇", 2: "🥈", 3: "🥉" };
  if (modeClassement === "equipes") {
    if (!etat.classement_equipes.length) return `<p class="vide">Aucune équipe n'a encore de joueur.</p>`;
    const monEquipe = etat.moi?.equipe_id;
    return `<div class="tableau-conteneur"><table>
      <thead><tr><th></th><th>Équipe</th><th class="nombre">Moyenne</th><th class="nombre">Joueurs</th></tr></thead>
      <tbody>${etat.classement_equipes.map((e) => `
        <tr class="${e.id === monEquipe ? "moi" : ""}">
          <td class="rang">${medailles[e.rang] || e.rang}</td><td>${esc(e.nom)}</td>
          <td class="nombre" title="Total de l'équipe : ${nb(e.total)} 🪙">${nb(e.moyenne)}</td>
          <td class="nombre aide">${e.nb_joueurs}</td>
        </tr>`).join("")}</tbody></table></div>`;
  }
  if (!etat.classement.length) return `<p class="vide">Aucun joueur inscrit.</p>`;
  const nomsEquipes = Object.fromEntries(etat.equipes.map((e) => [e.id, e.nom]));
  const lignes = etat.classement.map((j) => `
    <tr class="${etat.moi && j.id === etat.moi.id ? "moi" : ""}">
      <td class="rang">${medailles[j.rang] || j.rang}</td>
      <td><button class="lien-joueur" data-fiche="${j.id}">${esc(j.pseudo)}</button> ${icones(j.trophees)}${j.equipe_id ? ` <small class="aide">${esc(nomsEquipes[j.equipe_id] ?? "")}</small>` : ""}</td>
      <td class="nombre" title="Disponible : ${nb(j.disponible)} · En jeu : ${nb(j.en_jeu)}">${nb(j.total)}</td>
      <td class="nombre aide">${nb(j.en_jeu)}</td>
    </tr>`).join("");
  return `<div class="tableau-conteneur"><table>
    <thead><tr><th></th><th>Joueur</th><th class="nombre">🪙 Total</th><th class="nombre">En jeu</th></tr></thead>
    <tbody>${lignes}</tbody></table></div>`;
}

// ---------------------------------------------------------------------------
// Page d'accueil « marché »
// ---------------------------------------------------------------------------

const pourcent = (p) => p == null ? "—" : Math.round(p * 100) + " %";
const signe = (v) => (v > 0 ? "+" : v < 0 ? "−" : "") + nb(Math.abs(v));
const pts = (v) => v == null ? "" : v > 0 ? `<span class="variation hausse">▲ +${v} pts</span>`
  : v < 0 ? `<span class="variation baisse">▼ −${-v} pts</span>` : `<span class="variation stable">= 0 pt</span>`;

/** Variation à afficher : depuis la dernière dépêche si le marché a bougé depuis, sinon sur 24 h. */
const parDepeche = (t) => !!t?.depeche && t.issues.some((i) => i.variation_depeche);
const variationDe = (t, i) => (parDepeche(t) ? i.variation_depeche : i.variation);
function variationCourte(pr, t) {
  const v = pr && variationDe(t, pr);
  return v ? pts(v) : "";
}
const libelleDepuis = (t) => parDepeche(t) ? `depuis « ${esc(t.depeche.texte)} »` : t.depuis === "24h" ? "sur 24 h" : "depuis l'ouverture";

/**
 * Mini-courbe d'un marché sur 30 jours (0 à 100 %, repère à 50 %) : l'issue en tête pour un Oui/Non,
 * les 3 premières sinon, avec la valeur de départ et la valeur actuelle.
 */
function miniCourbe(t, p) {
  const n = t.issues.length === 2 ? 1 : Math.min(3, t.issues.length);
  const points = t.courbe;
  if (points.length < 2) return "";
  const W = 150, H = 54, g = 4, d = 4;
  const temps = points.map(([date]) => +new Date(date));
  const t0 = temps[0], t1 = temps[temps.length - 1] || t0 + 1;
  const x = (u) => g + (u - t0) / (t1 - t0 || 1) * (W - g - d), y = (v) => 4 + (1 - v) * (H - 16);
  const traces = [...Array(n).keys()].map((k) => {
    const chemin = points.map(([, ps], j) => `${j ? "L" : "M"}${x(temps[j]).toFixed(1)},${y(ps[k]).toFixed(1)}`).join("");
    const [xf, yf] = [x(temps[temps.length - 1]), y(points[points.length - 1][1][k])];
    return `<path d="${chemin}" fill="none" stroke="${COULEURS[k]}" stroke-width="2" stroke-linejoin="round"/>
      <circle cx="${xf}" cy="${yf}" r="2.8" fill="${COULEURS[k]}"/>`;
  }).join("");
  const jours = Math.round((Date.now() + decalageHorloge - t0) / 86400e3);
  const debut = jours >= 29 ? "J-30" : jours >= 1 ? `ouverture, J-${jours}` : "ouverture";
  const tete = p.issues.find((i) => i.id === t.issues[0].id)?.libelle ?? "";
  return `<figure class="mini-courbe">
    <svg viewBox="0 0 ${W} ${H}" role="img" aria-label="« ${esc(tete)} » : ${pourcent(points[0][1][0])} (${debut}), ${pourcent(t.issues[0].probabilite)} aujourd'hui">
      <title>« ${tete} » : ${pourcent(points[0][1][0])} (${debut}) → ${pourcent(t.issues[0].probabilite)}</title>
      <line x1="${g}" x2="${W - d}" y1="${y(0.5)}" y2="${y(0.5)}" class="mi-repere"/>
      ${traces}
      <text x="${g}" y="${H - 1}" class="mi-axe">${debut} · ${pourcent(points[0][1][0])}</text>
      <text x="${W - d}" y="${H - 1}" class="mi-axe" text-anchor="end">auj.</text>
    </svg></figure>`;
}

function tuileTendance(t) {
  const p = etat.paris.find((x) => x.id === t.pari_id);
  const libelle = (id) => p.issues.find((i) => i.id === id)?.libelle ?? "?";
  const tete = t.issues[0];
  return `
    <article class="tuile">
      <div class="tuile-entete">${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}${minuteur(p)}
        ${p.flash && p.accepte_mises ? `<span class="badge flash">⚡ <span data-fin="${esc(p.date_limite)}"></span></span>` : ""}
        ${p.statut === "suspendu" ? `<span class="badge suspendu">Suspendu</span>` : ""}</div>
      <a class="tuile-titre" href="#pari-${p.id}">${esc(p.titre)}</a>
      <div class="tuile-corps">
        <div class="tuile-chiffre">
          <span class="issue-tete">${esc(libelle(tete.id))}</span>
          <b>${pourcent(tete.probabilite)}</b>
          ${pts(variationDe(t, tete))}
          <small class="aide">${libelleDepuis(t)}</small>
        </div>
        ${miniCourbe(t, p)}
      </div>
      <div class="barre-proba" aria-hidden="true">${t.issues.map((i, n) =>
        `<span style="width:${(i.probabilite * 100).toFixed(1)}%;background:${COULEURS[n]}"></span>`).join("")}</div>
      <div class="tuile-issues">${t.issues.slice(0, 4).map((i) =>
        `<a class="bouton-issue" href="#pari-${p.id}" data-issue-cible="${i.id}">${esc(libelle(i.id))} <b>${pourcent(i.probabilite)}</b></a>`).join("")}
        ${t.issues.length > 4 ? `<span class="aide">+${t.issues.length - 4}</span>` : ""}</div>
      <div class="aide tuile-pied">${nb(p.total_mise)} 🪙 en jeu${t.volume_24h ? ` · ${nb(t.volume_24h)} 🪙 sur 24 h` : ""} · ${pluriel(p.nb_joueurs, "joueur")}
        ${p.commentaires.length ? ` · 💬 ${p.commentaires.length}` : ""}</div>
    </article>`;
}

/** Bonus quotidien sur l'accueil : à récupérer, ou compte à rebours et série de jours. */
function blocBonus() {
  const b = etat.bonus;
  if (!b.montant) return "";
  const serie = b.serie >= 2 ? ` · 🔥 ${b.serie} jours d'affilée` : "";
  return b.disponible
    ? `<div class="hero-bonus pret">🎁 Votre bonus quotidien vous attend : <b>${nb(b.montant)} deniers publics</b>${serie}
        <button data-action="bonus">Récupérer</button></div>`
    : `<div class="hero-bonus">🎁 Bonus récupéré${serie}. Prochain dans <b data-fin="${esc(b.prochain)}" data-sorte="bonus"></b> :
        revenez demain !</div>`;
}

/** Pari du jour : mise directe depuis l'accueil, grand graphique des probabilités. */
function blocPariDuJour() {
  if (!etat.pari_du_jour) return "";
  const p = etat.paris.find((x) => x.id === etat.pari_du_jour.id);
  if (!p) return "";
  const t = marche(p);
  const ordre = t ? t.issues.map((i) => p.issues.find((x) => x.id === i.id)) : p.issues;
  return `
    <section class="carte pari-du-jour" id="pari-du-jour">
      <div class="pdj-entete"><span class="pdj-etiquette">⭐ Pari du jour</span>${minuteur(p)}
        <span class="aide">${etat.pari_du_jour.choisi ? "choisi par l'organisation" : "le plus animé des dernières 24 heures"}</span>
        ${p.flash ? `<span class="badge flash">⚡ <span data-fin="${esc(p.date_limite)}"></span></span>` : ""}
        <button class="lien lien-pari" data-action="copier-lien" data-pari-lien="${p.id}">🔗 Lien</button></div>
      <h2><a href="#pari-${p.id}">${esc(p.titre)}</a></h2>
      ${p.description ? `<p class="desc">${esc(p.description)}</p>` : ""}
      ${etat.bonus.question.montant ? `<p class="pdj-bonus${etat.bonus.question.obtenu ? " obtenu" : ""}">${etat.bonus.question.obtenu
        ? `✅ Vous avez déjà eu aujourd'hui votre bonus de ${nb(etat.bonus.question.montant)} 🪙 pour la question du jour.`
        : `🎁 <b>+${nb(etat.bonus.question.montant)} deniers publics offerts</b> pour votre première mise du jour sur cette question, quelle que soit votre réponse.`}</p>` : ""}
      <div class="pdj-corps">
        <div class="pdj-issues">${p.type === "estimation" ? blocEstimation(p)
          : `<div class="issues">${ordre.map((i) => ligneIssue(p, i, "pj")).join("")}</div>`}
          ${!etat.moi ? `<p class="aide">Créez un compte ou connectez-vous pour miser.</p>` : ""}</div>
        ${t ? `<div class="pdj-graphique"><div class="aide">Probabilités ${libelleDepuis(t)} : ${t.issues.slice(0, 3).map((i) =>
          `${esc(p.issues.find((x) => x.id === i.id)?.libelle)} ${pts(variationDe(t, i)) || "="}`).join(" · ")}</div>
          <div class="graphique" data-graphique-pdj="${p.id}"></div></div>` : ""}
      </div>
    </section>`;
}

function vueAccueil() {
  const d = etat.direct;
  // Ordre choisi par l'administration (celui de etat.paris), paris flash ouverts en tête
  const rang = Object.fromEntries(etat.paris.map((p, k) => [p.id, k]));
  const tendances = [...etat.tendances].filter((t) => t.pari_id !== etat.pari_du_jour?.id).sort((a, b) => {
    const pa = etat.paris[rang[a.pari_id]], pb = etat.paris[rang[b.pari_id]];
    return (pb.flash && pb.accepte_mises) - (pa.flash && pa.accepte_mises) || rang[a.pari_id] - rang[b.pari_id];
  });
  const maintenant = new Date(etat.maintenant);
  const ouverts = etat.paris.filter((p) => p.accepte_mises);
  const bientot = ouverts.filter((p) => p.date_limite && new Date(p.date_limite) - maintenant < 48 * 3600e3)
    .sort((a, b) => a.date_limite.localeCompare(b.date_limite)).slice(0, 5);
  const resultats = etat.paris.filter((p) => p.statut === "clos").slice(0, 4);
  const etape = etat.etapes.find((e) => e.date >= etat.maintenant.slice(0, 10));
  const estimations = ouverts.filter((p) => p.type === "estimation");
  const depeche = etat.depeches.find((x) => maintenant - new Date(x.date) < 24 * 3600e3);
  const enDirect = etat.fil.filter((e) => ["mise", "mouvement", "flash", "depeche", "commentaire", "clos"].includes(e.type)).slice(0, 4);
  const moi = etat.moi;
  return `
    <section class="accueil-hero">
      <div class="hero-haut"><h2>Les paris du PLF</h2><span class="hero-direct"><i></i>En direct</span></div>
      <p class="slogan">Le seul endroit où perdre 500 milliards ne coûte que 200 deniers.</p>
      <div class="chiffres-cles">
        <div><b data-compteur="mises_jour" data-valeur="${d.mises_jour}">${nb(d.mises_jour)}</b><span>deniers misés aujourd'hui</span></div>
        <div><b data-compteur="marches_ouverts" data-valeur="${d.marches_ouverts}">${d.marches_ouverts}</b><span>marché${d.marches_ouverts > 1 ? "s" : ""} ouvert${d.marches_ouverts > 1 ? "s" : ""}</span></div>
        <div><b data-compteur="joueurs" data-valeur="${d.joueurs}">${d.joueurs}</b><span>joueur${d.joueurs > 1 ? "s" : ""}${d.joueurs_actifs_jour ? `, dont ${d.joueurs_actifs_jour} aujourd'hui` : ""}</span></div>
        <div><b data-compteur="en_jeu" data-valeur="${d.en_jeu}">${nb(d.en_jeu)}</b><span>deniers en jeu</span></div>
      </div>
      ${depeche ? `<p class="hero-depeche">📰 <b>${esc(depeche.texte)}</b> <span>· ${ilYa(depeche.date)}</span></p>` : ""}
      ${enDirect.length ? `<ul class="hero-flux">${enDirect.map((e) => `<li><time>${ilYa(e.date)}</time> ${texteEvenement(e)}</li>`).join("")}</ul>` : ""}
      ${moi ? `<p class="accueil-moi">Vous avez <b>${nb(moi.disponible)} 🪙</b> à miser · ${moi.rang}<sup>${moi.rang === 1 ? "er" : "e"}</sup> sur ${etat.classement.length}
          · <button class="lien" data-fiche="${moi.id}">ma fiche</button></p>${blocBonus()}`
        : `<div class="appel"><button data-onglet="inscription">Créer un compte · ${nb(etat.capital)} 🪙 offerts</button>
           <span>Déjà inscrit ? Connectez-vous en haut à droite.</span></div>`}
    </section>

    ${blocPariDuJour()}

    <div class="carte-titre titre-section"><h2>📈 Tendances</h2><button class="lien" data-onglet="ouverts">Tous les paris →</button></div>
    ${tendances.length ? `<div class="grille-tuiles">${tendances.slice(0, 9).map(tuileTendance).join("")}</div>`
      : `<p class="vide">Aucun autre pari ouvert pour l'instant.</p>`}

    <div class="accueil-colonnes">
      <div class="carte">
        <h2>⏳ Clôturent bientôt</h2>
        ${bientot.length ? `<ul class="liste-simple">${bientot.map((p) =>
          `<li><a href="#pari-${p.id}">${esc(p.titre)}</a> <span class="aide">${p.flash ? `⚡ <span data-fin="${esc(p.date_limite)}"></span>` : fmtDate(p.date_limite)}</span></li>`).join("")}</ul>`
          : `<p class="aide">Aucun pari ne ferme dans les 48 heures.</p>`}
        ${estimations.length ? `<h3>🔢 À estimer</h3><ul class="liste-simple">${estimations.slice(0, 4).map((p) =>
          `<li><a href="#pari-${p.id}">${esc(p.titre)}</a> <span class="aide">${pluriel(p.nb_joueurs, "estimation")}</span></li>`).join("")}</ul>` : ""}
        ${etape ? `<h3>📅 Prochaine étape</h3><p><b>${fmtJour(etape.date)}</b> · ${esc(etape.titre)}</p>` : ""}
      </div>
      <div class="carte">
        <h2>🏁 Derniers résultats</h2>
        ${resultats.length ? `<ul class="liste-simple">${resultats.map((p) => `<li><a href="#pari-${p.id}">${esc(p.titre)}</a>
          <span class="resultat">${p.type === "estimation" ? esc(avecUnite(p.valeur_reelle, p.unite))
            : esc(p.issues.find((i) => i.id === p.issue_gagnante_id)?.libelle ?? "")}</span></li>`).join("")}</ul>`
          : `<p class="aide">Aucun pari clôturé pour l'instant.</p>`}
      </div>
    </div>`;
}

/** Fiche du joueur connecté dans « Mon profil » (rechargée quand l'état change). */
async function majFicheProfil() {
  const cible = $("#fiche-profil");
  if (onglet !== "profil" || !cible || !etat.moi || cible._v === etat.v || majFicheProfil.enCours) return;
  majFicheProfil.enCours = true;
  try {
    const html = vueFiche(await api(`fiche&joueur=${etat.moi.id}`)).replace(/<button class="lien fermer"[^>]*>✕<\/button>/, "");
    const actuelle = $("#fiche-profil");
    if (actuelle) { actuelle.innerHTML = html; actuelle._v = etat.v; }
  } catch (e) {
    cible.innerHTML = `<p class="vide">${esc(e.message)}</p>`;
  } finally {
    majFicheProfil.enCours = false;
  }
}

/** Grand graphique du pari du jour (dessiné après le rendu, à partir des tendances). */
function majPariDuJour() {
  const cible = $("[data-graphique-pdj]");
  if (!cible) return;
  const p = etat.paris.find((x) => x.id === Number(cible.dataset.graphiquePdj)), t = marche(p);
  if (!t || cible._v === etat.v && cible.childNodes.length) return;
  cible._v = etat.v;
  graphique(cible, t.issues.map((i, k) => ({
    nom: p.issues.find((x) => x.id === i.id)?.libelle ?? "?",
    points: t.courbe.map(([date, ps]) => [new Date(date), ps[k]]),
  })), { format: pourcent, domaine: [0, 1], reperes: etat.depeches });
}

const compteursAffiches = {};
/** Chiffres en direct : défilement de l'ancienne à la nouvelle valeur, avec un éclair. */
function animerCompteurs() {
  $$("[data-compteur]").forEach((el) => {
    const cle = el.dataset.compteur, cible = Number(el.dataset.valeur), depart = compteursAffiches[cle];
    compteursAffiches[cle] = cible;
    if (depart == null || depart === cible || matchMedia("(prefers-reduced-motion: reduce)").matches) return;
    const debut = performance.now();
    const pas = (t) => {
      const avance = Math.min(1, (t - debut) / 900);
      el.textContent = nb(depart + (cible - depart) * (1 - (1 - avance) ** 3));
      if (avance < 1) requestAnimationFrame(pas);
    };
    requestAnimationFrame(pas);
    el.parentElement.classList.remove("bouge");
    void el.offsetWidth;
    el.parentElement.classList.add("bouge");
  });
}

// ---------------------------------------------------------------------------
// Fiche joueur (fenêtre)
// ---------------------------------------------------------------------------

function vueFiche(f) {
  const serie = f.serie.longueur >= 2 ? `${f.serie.sens === "gagnee" ? "🔥" : "🧊"} ${f.serie.longueur} ${f.serie.sens === "gagnee" ? "gagnés" : "perdus"} d'affilée`
    : f.serie.longueur === 1 ? (f.serie.sens === "gagnee" ? "dernier pari gagné" : "dernier pari perdu") : "—";
  const pari = (x, classe) => x ? `<a href="#pari-${x.pari_id}" data-action="fermer-fiche">${esc(x.titre)}</a>
    <b class="${classe}">${signe(x.net)} 🪙</b><span class="aide">mise ${nb(x.mise)}</span>` : `<span class="aide">—</span>`;
  const resultats = { gagne: "✅", perdu: "❌", en_cours: "⏳", rembourse: "↩️" };
  return `
    <div class="fiche-entete">
      <div><h2>${esc(f.pseudo)} ${f.trophees.map((t) => `<span title="${esc(t.nom)}">${t.icone}</span>`).join("")}</h2>
        <p class="aide">${f.rang}<sup>${f.rang === 1 ? "er" : "e"}</sup> sur ${f.nb_joueurs}${f.equipe ? ` · ${esc(f.equipe)}` : ""} · inscrit le ${fmtDate(f.inscrit_le)}</p></div>
      <button class="lien fermer" data-action="fermer-fiche" aria-label="Fermer">✕</button>
    </div>
    <div class="fiche-stats">
      <div><span>Total</span><b>${nb(f.total)} 🪙</b>
        <small class="${f.variation_24h > 0 ? "hausse" : f.variation_24h < 0 ? "baisse" : ""}">${signe(f.variation_24h)} sur 24 h</small>
        <small class="${f.variation_7j > 0 ? "hausse" : f.variation_7j < 0 ? "baisse" : ""}">${signe(f.variation_7j)} sur 7 j</small></div>
      <div><span>Paris gagnés</span><b>${f.taux_reussite == null ? "—" : Math.round(f.taux_reussite * 100) + " %"}</b>
        <small>${f.paris_gagnes} sur ${f.paris_joues} clôturé${f.paris_joues > 1 ? "s" : ""}</small></div>
      <div><span>Série en cours</span><b class="petit">${serie}</b></div>
      <div><span>En jeu</span><b>${nb(f.en_jeu)} 🪙</b><small>${nb(f.disponible)} disponibles</small></div>
    </div>
    <div class="fiche-paris">
      <div><span>🏆 Meilleur pari</span>${pari(f.meilleur_pari, "hausse")}</div>
      <div><span>💥 Plus grosse perte</span>${pari(f.plus_grosse_perte, "baisse")}</div>
    </div>
    ${f.dernieres_mises.length ? `<h3>Dernières mises</h3><ul class="liste-simple">${f.dernieres_mises.map((m) =>
      `<li><a href="#pari-${m.pari_id}" data-action="fermer-fiche">${resultats[m.resultat]} ${esc(m.titre)}</a>
        <span class="aide">${nb(m.montant)} 🪙${m.issue ? ` sur « ${esc(m.issue)} »` : ""}${m.gain != null && m.resultat === "gagne" ? ` → ${nb(m.gain)}` : ""}</span></li>`).join("")}</ul>` : ""}`;
}

async function ouvrirFiche(id) {
  const fenetre = $("#fiche");
  $("#fiche-contenu").innerHTML = `<p class="vide">Chargement…</p>`;
  if (!fenetre.open) fenetre.showModal();
  try {
    $("#fiche-contenu").innerHTML = vueFiche(await api(`fiche&joueur=${id}`));
  } catch (e) {
    $("#fiche-contenu").innerHTML = `<p class="vide">${esc(e.message)}</p>`;
  }
}

// ---------------------------------------------------------------------------
// Inscription et « Mon profil »
// ---------------------------------------------------------------------------

const aideEquipe = `<span class="aide">Choisissez une équipe de la liste, ou tapez un nouveau nom pour la créer
  (« DG75 », « dg 75 » et « DG-75 » désignent la même équipe). Les équipes sont classées à la moyenne de leurs joueurs.</span>`;

function vueInscription() {
  if (etat.moi) return `<p class="vide">Vous êtes connecté en tant que ${esc(etat.moi.pseudo)}.</p>`;
  return `
    <div class="carte">
      <h2>Créer un compte</h2>
      <form id="form-inscription" class="form-pari">
        <div class="deux-colonnes">
          <label>Pseudo<input name="pseudo" data-k="i-pseudo" minlength="2" maxlength="30" autocomplete="username" required></label>
          <label>Code secret (4 caractères minimum)<input name="pin" data-k="i-pin" type="password" minlength="4" maxlength="64" autocomplete="new-password" required></label>
        </div>
        ${etat.invitation ? `<label>Code d'invitation<input name="invitation" data-k="i-invitation" autocomplete="off" required
          placeholder="Communiqué par l'organisateur du jeu"></label>` : ""}
        <label>Équipe (facultatif)<input name="equipe" data-k="i-equipe" list="liste-equipes" maxlength="40" autocomplete="off"
          placeholder="${etat.equipes.length ? "Ex. : " + esc(etat.equipes.slice(0, 2).map((e) => e.nom).join(", ")) : "Ex. : DG75"}"></label>
        ${aideEquipe}
        <div><button type="submit">Créer mon compte et recevoir ${nb(etat.capital)} 🪙</button></div>
      </form>
    </div>`;
}

function vueProfil() {
  const moi = etat.moi;
  if (!moi) return `<p class="vide">Connectez-vous pour accéder à votre profil.</p>`;
  const equipe = etat.equipes.find((e) => e.id === moi.equipe_id);
  const rangEquipe = etat.classement_equipes.find((e) => e.id === moi.equipe_id);
  const coequipiers = etat.classement.filter((j) => j.equipe_id === moi.equipe_id && moi.equipe_id);
  const catalogue = Object.entries(etat.trophees).map(([code, t]) => {
    const date = etat.mes_trophees[code];
    return `<li class="${date ? "obtenu" : ""}"><span class="icone">${t.icone}</span>
      <div><b>${esc(t.nom)}</b><br><span class="aide">${esc(t.condition)}${date ? ` · obtenu le ${fmtDate(date)}` : ""}</span></div></li>`;
  }).join("");
  return `
    <div class="carte fiche-profil" id="fiche-profil"><p class="vide">Chargement de votre fiche…</p></div>
    <div class="carte">
      <h2>Mon équipe</h2>
      <p>${equipe ? `<b>${esc(equipe.nom)}</b>${rangEquipe ? ` · ${rangEquipe.rang}<sup>${rangEquipe.rang === 1 ? "re" : "e"}</sup> équipe sur
        ${etat.classement_equipes.length}, moyenne ${nb(rangEquipe.moyenne)} 🪙` : ""}` : "Vous n'êtes dans aucune équipe."}</p>
      ${coequipiers.length > 1 ? `<p class="aide">Avec : ${coequipiers.filter((j) => j.id !== moi.id).map((j) => esc(j.pseudo)).join(", ")}</p>` : ""}
      <form id="form-profil" class="ligne-form">
        <input name="equipe" data-k="p-equipe" list="liste-equipes" maxlength="40" autocomplete="off"
          placeholder="${equipe ? "Changer d'équipe…" : "Rejoindre ou créer une équipe…"}">
        <button type="submit">${equipe ? "Changer" : "Rejoindre"}</button>
        ${equipe ? `<button type="button" class="lien" data-action="quitter-equipe">Quitter l'équipe</button>` : ""}
      </form>
      ${aideEquipe}
    </div>
    <div class="carte">
      <h2>Trophées</h2>
      <ul class="trophees">${catalogue}</ul>
    </div>`;
}

// ---------------------------------------------------------------------------
// Paris flash : compte à rebours
// ---------------------------------------------------------------------------


/** Met à jour les comptes à rebours ; recharge l'état quand l'un d'eux arrive à zéro. */
function majComptesARebours() {
  const maintenant = Date.now() + decalageHorloge;
  let flashOuvert = false, termine = false;
  $$("[data-fin]").forEach((el) => {
    const reste = Math.max(0, Math.round((new Date(el.dataset.fin) - maintenant) / 1000));
    el.textContent = reste >= 86400 ? `${Math.floor(reste / 86400)} j ${Math.floor(reste % 86400 / 3600)} h`
      : reste >= 3600 ? `${Math.floor(reste / 3600)} h ${String(Math.floor(reste % 3600 / 60)).padStart(2, "0")} min`
      : reste >= 60 ? `${Math.floor(reste / 60)} min ${String(reste % 60).padStart(2, "0")} s` : `${reste} s`;
    const puce = el.closest(".minuteur");
    if (puce) {
      puce.classList.toggle("bientot", reste < 86400);
      puce.classList.toggle("urgent", reste < 3600);
    } else if (!el.dataset.sorte) {
      el.closest(".pari")?.classList.toggle("urgent", reste <= 60); // pari flash
    }
    if (reste > 0) { if (!el.dataset.sorte) flashOuvert = true; } else termine = true;
  });
  document.title = (flashOuvert ? "⚡ " : "") + "Les paris du PLF";
  if (termine && !majComptesARebours.enCours) {
    majComptesARebours.enCours = true;
    setTimeout(() => { majComptesARebours.enCours = false; rafraichir(); }, 1500);
  }
}

// ---------------------------------------------------------------------------
// Administration
// ---------------------------------------------------------------------------

const libelleIssue = (p, id) => p.issues.find((i) => i.id === id)?.libelle ?? "?";
const pseudoDe = (id) => etat.classement.find((j) => j.id === id)?.pseudo ?? "?";

/** Formulaire de modification d'un pari (issues ajoutables / retirables seulement s'il est en cours). */
function editionPari(p, actif) {
  const k = `ed-${p.id}`;
  const issues = p.type === "estimation" ? `
        <label>Unité<input data-k="${k}-unite" maxlength="30" value="${esc(p.unite)}"></label>` : `
        <fieldset><legend>Issues</legend>
          ${p.issues.map((i) => `<div class="ligne-issue">
            <input data-k="${k}-i-${i.id}" data-issue="${i.id}" maxlength="80" value="${esc(i.libelle)}">
            ${!actif ? "" : i.total_mise ? `<span class="aide">${nb(i.total_mise)} 🪙 misés</span>`
              : `<label class="aide"><input type="checkbox" data-k="${k}-r-${i.id}" data-retirer="${i.id}"> retirer</label>`}
          </div>`).join("")}
          ${actif ? `<input data-k="${k}-nouvelle" maxlength="80" placeholder="Ajouter une issue (facultatif)">` : ""}
        </fieldset>`;
  return `
    <details class="edition" data-k="${k}"><summary>Modifier le pari</summary>
      <div class="form-pari">
        <label>Intitulé<input data-k="${k}-titre" maxlength="200" value="${esc(p.titre)}"></label>
        <div class="deux-colonnes">
          <label>Catégorie<input data-k="${k}-cat" maxlength="40" list="categories" value="${esc(p.categorie)}"></label>
          <label>Fin des mises<input type="datetime-local" data-k="${k}-date" value="${p.date_limite || ""}"></label>
        </div>
        <label>Étape du calendrier<select data-k="${k}-etape"><option value="">— Aucune —</option>${optionsEtapes(p.etape_id)}</select></label>
        <label>Précisions / règle d'arbitrage<textarea data-k="${k}-desc" rows="2" maxlength="500">${esc(p.description)}</textarea></label>
        ${issues}
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
        <thead><tr><th>Date</th><th>Joueur</th><th>${p.type === "estimation" ? "Estimation" : "Issue"}</th><th class="nombre">Mise</th><th class="nombre">${actif ? "" : "Gain"}</th></tr></thead>
        <tbody>${mises.map((m) => `<tr>
          <td>${fmtDate(m.cree_le)}</td><td>${esc(pseudoDe(m.joueur_id))}${m.par_admin ? ` <small class="aide">(admin)</small>` : ""}</td>
          <td>${p.type === "estimation" ? esc(avecUnite(m.estimation, p.unite)) : esc(libelleIssue(p, m.issue_id))}</td><td class="nombre">${nb(m.montant)}</td>
          <td class="nombre">${actif ? `<button class="lien" data-action="admin-suppression-mise" data-mise="${m.id}">supprimer</button>`
            : m.tardive ? "remboursée (tardive)" : nb(m.gain ?? 0)}</td>
        </tr>`).join("")}</tbody>
      </table></div>
    </details>`;
}

function vueAdminParis() {
  const actifs = etat.paris.filter((p) => p.statut === "ouvert" || p.statut === "suspendu");
  const clos = etat.paris.filter((p) => p.statut === "clos" || p.statut === "annule");
  const enBloc = etat.donnees_admin.suspendus_en_bloc.length;
  const nbOuverts = etat.paris.filter((p) => p.statut === "ouvert").length;
  const optionsJoueurs = [...etat.classement].sort((a, b) => a.pseudo.localeCompare(b.pseudo))
    .map((j) => `<option value="${j.id}">${esc(j.pseudo)} (${nb(j.disponible)} 🪙)</option>`).join("");
  const barre = `
    <div class="carte actions-globales">
      <button class="danger" data-action="admin-tout-suspendre" ${nbOuverts ? "" : "disabled"}>⏸ Tout suspendre (${nbOuverts})</button>
      ${enBloc ? `<button class="secondaire" data-action="admin-tout-rouvrir">▶ Rouvrir les ${pluriel(enBloc, "pari")} suspendus en bloc</button>` : ""}
      <span class="aide">En pleine séance, coupe d'un coup les mises de tous les paris ouverts. Pensez aussi à
        indiquer à la clôture l'heure où le résultat a été connu : les mises placées depuis seront remboursées.</span>
    </div>`;
  const cartes = actifs.map((p) => {
    const estimation = p.type === "estimation";
    const cotes = estimation ? `<p class="aide">${pluriel(p.nb_joueurs, "estimation")} · cagnotte ${nb(p.total_mise)} 🪙 + ${nb(etat.amorce)} 🪙 de la banque.</p>` : `
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
      </div>`;
    return `
    <article class="carte admin-pari" data-pari="${p.id}">
      <div class="pari-entete">${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}
        ${estimation ? `<span class="badge estimation">Chiffre</span>` : ""}${badgeStatut(p)}
        <span class="meta">${pluriel(p.nb_joueurs, "joueur")} · cagnotte ${nb(p.total_mise)} 🪙</span></div>
      <h3>${esc(p.titre)}</h3>
      ${p.auteur ? `<p class="auteur">Proposé par ${esc(p.auteur)}</p>` : ""}
      ${cotes}
      ${listeMisesAdmin(p)}
      ${editionPari(p, true)}
      <div class="actions">
        <select data-k="mj-${p.id}"><option value="">— Miser pour… —</option>${optionsJoueurs}</select>
        ${estimation ? `<input inputmode="decimal" placeholder="Estimation" data-k="me-${p.id}" class="petit">`
          : `<select data-k="mi-${p.id}">${p.issues.map((i) => `<option value="${i.id}">${esc(i.libelle)}</option>`).join("")}</select>`}
        <input type="number" min="1" step="1" placeholder="Mise" data-k="mm-${p.id}" class="petit">
        <button class="secondaire" data-action="admin-miser">Miser</button>
      </div>
      <div class="actions">
        <button class="secondaire" data-action="admin-statut" data-statut="${p.statut === "ouvert" ? "suspendu" : "ouvert"}">
          ${p.statut === "ouvert" ? "Suspendre les mises" : "Rouvrir les mises"}</button>
        ${etat.pari_du_jour?.id === p.id
          ? `<span class="badge pdj">⭐ Pari du jour${etat.pari_du_jour.choisi ? "" : " (automatique)"}</span>
             ${etat.pari_du_jour.choisi ? `<button class="lien" data-action="admin-pdj" data-pdj="">Revenir au choix automatique</button>` : ""}`
          : p.accepte_mises ? `<button class="secondaire" data-action="admin-pdj" data-pdj="${p.id}">⭐ En faire le pari du jour</button>` : ""}
      </div>
      <div class="actions cloture">
        ${estimation ? `<input inputmode="decimal" placeholder="Valeur réelle${p.unite ? ` (${esc(p.unite)})` : ""}" data-k="vr-${p.id}">`
          : `<select data-k="w-${p.id}"><option value="">— Issue réalisée —</option>
          ${p.issues.map((i) => `<option value="${i.id}">${esc(i.libelle)}</option>`).join("")}</select>`}
        <label class="aide" title="Les mises placées à partir de cette heure seront remboursées">Résultat connu le
          <input type="datetime-local" data-k="rl-${p.id}"></label>
        <button data-action="admin-cloture">Clôturer et payer</button>
        <button class="danger" data-action="admin-annulation">Annuler (rembourser)</button>
        ${p.total_mise === 0 ? `<button class="lien" data-action="admin-suppression">Supprimer</button>` : ""}
      </div>
    </article>`;
  });
  const cartesClos = clos.map((p) => `
    <article class="carte admin-pari" data-pari="${p.id}">
      <div class="pari-entete">${p.categorie ? `<span class="badge">${esc(p.categorie)}</span>` : ""}${badgeStatut(p)}
        <span class="meta">${p.clos_le ? `le ${fmtDate(p.clos_le)} · ` : ""}cagnotte ${nb(p.total_mise)} 🪙</span></div>
      <h3>${esc(p.titre)}</h3>
      <p>${p.statut === "annule" ? "Annulé, mises remboursées."
        : p.type === "estimation" ? `Valeur réelle : ${esc(avecUnite(p.valeur_reelle, p.unite))}`
        : `Issue réalisée : « ${esc(libelleIssue(p, p.issue_gagnante_id))} »`}
        ${p.realise_le ? `<span class="aide">(résultat connu le ${fmtDate(p.realise_le)}, ${pluriel(p.nb_tardives, "mise tardive")})</span>` : ""}</p>
      ${listeMisesAdmin(p)}
      ${editionPari(p, false)}
      <div class="actions">
        <button class="secondaire" data-action="admin-reouverture">Revenir sur ${p.statut === "annule" ? "l'annulation" : "la clôture"}</button>
      </div>
    </article>`);
  const flash = `
    <div class="carte lancer-flash">
      <h2>⚡ Lancer un pari flash</h2>
      <div class="actions">
        <input data-k="fl-titre" maxlength="200" placeholder="Ex. : Le ministre est-il interrompu dans les 5 minutes ?" class="large">
        <input data-k="fl-issues" maxlength="200" value="Oui / Non" title="Issues séparées par « / »" class="petit-moyen">
        <select data-k="fl-minutes">${[2, 5, 10, 15, 30, 60].map((m) => `<option value="${m}"${m === 10 ? " selected" : ""}>${m} min</option>`).join("")}</select>
        <button data-action="admin-flash">⚡ Lancer</button>
      </div>
      <p class="aide">Ouvert tout de suite, avec un compte à rebours bien visible et une annonce dans le bandeau.
        Pensez à le clôturer rapidement, avec l'heure du résultat.</p>
    </div>`;
  const depeches = `
    <div class="carte lancer-depeche">
      <h2>📰 Publier une dépêche</h2>
      <div class="actions">
        <input data-k="dp-texte" maxlength="140" placeholder="Ex. : Réunion à Matignon, le Gouvernement consulte les groupes" class="large">
        <button data-action="admin-depeche">Publier</button>
      </div>
      <p class="aide">Annoncée dans le bandeau et sur l'accueil ; pendant 7 jours, les variations des marchés sont calculées
        depuis la dernière dépêche (« ▲ +14 pts depuis « Réunion à Matignon » ») et les mouvements de 10 points ou plus sont
        annoncés automatiquement. Elle apparaît aussi comme repère sur les courbes.</p>
      ${etat.depeches.length ? `<ul class="liste-simple">${etat.depeches.slice(0, 5).map((x) => `<li><span>${fmtDate(x.date)} · ${esc(x.texte)}</span>
        <button class="lien" data-action="admin-depeche-suppression" data-depeche="${x.id}">supprimer</button></li>`).join("")}</ul>` : ""}
    </div>`;
  const pdj = etat.pari_du_jour;
  const ouverts = actifs.filter((p) => p.accepte_mises);
  const ordre = `
    <div class="carte ordre-questions">
      <h2>⭐ Question du jour</h2>
      <div class="actions">
        <select data-k="pdj-choix">
          <option value="">Automatique : le pari le plus animé des dernières 24 h</option>
          ${ouverts.map((p) => `<option value="${p.id}"${pdj?.choisi && pdj.id === p.id ? " selected" : ""}>${esc(p.titre)}</option>`).join("")}
        </select>
        <button data-action="admin-pdj-choix">Enregistrer</button>
      </div>
      <p class="aide">Actuellement : ${pdj ? `« ${esc(etat.paris.find((p) => p.id === pdj.id)?.titre ?? "")} »
        ${pdj.choisi ? "(votre choix, valable jusqu'à ce que vous en changiez ou que le pari ferme)" : "(choix automatique)"}` : "aucun pari ouvert"}.</p>
      <h2>🗂 Ordre des questions</h2>
      <p class="aide">Ordre d'affichage dans « Paris en cours » et dans les tendances de l'accueil (les paris flash ouverts
        restent toujours en tête). Les nouveaux paris arrivent en fin de liste.</p>
      ${actifs.length ? `<ol class="liste-ordre">${actifs.map((p, k) => `
        <li data-pari="${p.id}">
          <span class="ordre-titre">${p.flash ? "⚡ " : ""}${esc(p.titre)}${p.statut === "suspendu" ? ` <span class="badge suspendu">suspendu</span>` : ""}
            ${p.date_limite ? `<span class="aide">⏳ ${fmtDate(p.date_limite)}</span>`
              : `<span class="sans-date" title="Fixez une date de fin (« Modifier le pari ») pour afficher un minuteur">sans date limite</span>`}</span>
          <span class="ordre-boutons">
            <button class="secondaire" data-action="admin-deplacer" data-sens="haut" title="Tout en haut" ${k === 0 ? "disabled" : ""}>⤒</button>
            <button class="secondaire" data-action="admin-deplacer" data-sens="monter" title="Monter" ${k === 0 ? "disabled" : ""}>↑</button>
            <button class="secondaire" data-action="admin-deplacer" data-sens="descendre" title="Descendre" ${k === actifs.length - 1 ? "disabled" : ""}>↓</button>
            <button class="secondaire" data-action="admin-deplacer" data-sens="bas" title="Tout en bas" ${k === actifs.length - 1 ? "disabled" : ""}>⤓</button>
          </span>
        </li>`).join("")}</ol>` : `<p class="aide">Aucun pari en cours.</p>`}
    </div>`;
  return ordre + flash + depeches + barre + (cartes.join("") || `<p class="vide">Aucun pari en cours.</p>`)
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
        <span class="meta">${j.rang}<sup>e</sup> · disponible ${nb(j.disponible)} · en jeu ${nb(j.en_jeu)} · total ${nb(j.total)} 🪙 · ${pluriel(nbMises, "mise")}</span></summary>
      <div class="actions">
        <label class="aide">Pseudo <input data-k="jp-${j.id}" maxlength="30" value="${esc(j.pseudo)}"></label>
        <label class="aide">Nouveau code <input data-k="jc-${j.id}" maxlength="64" placeholder="inchangé" autocomplete="off"></label>
        ${etat.equipes.length ? `<label class="aide">Équipe <select data-k="je-${j.id}">${optionsEquipes(j.equipe_id)}</select></label>` : ""}
        <button class="secondaire" data-action="admin-joueur-maj">Enregistrer</button>
      </div>
      <div class="actions">
        <label class="aide">Deniers publics <input type="number" step="1" data-k="ja-${j.id}" placeholder="+100 ou -50" class="petit"></label>
        <label class="aide">Motif <input data-k="jm-${j.id}" maxlength="200" placeholder="facultatif, visible de tous"></label>
        <button class="secondaire" data-action="admin-ajustement">Créditer / débiter</button>
      </div>
      ${ajustements.length ? `<ul class="aide">${ajustements.map((a) =>
        `<li>${fmtDate(a.cree_le)} : ${a.montant > 0 ? "+" : ""}${nb(a.montant)} 🪙${a.motif ? ` (${esc(a.motif)})` : ""}</li>`).join("")}</ul>` : ""}
      <div class="actions"><button class="danger" data-action="admin-joueur-suppression">Supprimer le joueur</button></div>
    </details>`;
  }).join("");
}

function vueAdminCalendrier() {
  const etapes = etat.etapes.map((e) => {
    const lies = etat.paris.filter((p) => p.etape_id === e.id).length;
    return `
    <div class="carte admin-etape" data-etape="${e.id}">
      <div class="actions">
        <input type="date" data-k="et-${e.id}-date" value="${e.date}">
        <input data-k="et-${e.id}-titre" maxlength="150" value="${esc(e.titre)}" class="large">
      </div>
      <textarea data-k="et-${e.id}-desc" rows="2" maxlength="500" placeholder="Précisions (facultatif)">${esc(e.description)}</textarea>
      <div class="actions">
        <button class="secondaire" data-action="admin-etape-maj">Enregistrer</button>
        <button class="lien" data-action="admin-etape-suppression">Supprimer</button>
        <span class="aide">${pluriel(lies, "pari")} rattaché${lies > 1 ? "s" : ""}</span>
      </div>
    </div>`;
  }).join("");
  return `
    <div class="carte">
      <h2>Ajouter une étape</h2>
      <div class="actions">
        <input type="date" data-k="et-nouvelle-date">
        <input data-k="et-nouvelle-titre" maxlength="150" placeholder="Ex. : Vote solennel de la première partie" class="large">
      </div>
      <textarea data-k="et-nouvelle-desc" rows="2" maxlength="500" placeholder="Précisions (facultatif)"></textarea>
      <div class="actions"><button data-action="admin-etape-ajout">Ajouter au calendrier</button></div>
      <p class="aide">Les joueurs et l'administration peuvent rattacher chaque pari à une étape ; l'onglet « Calendrier » les affiche sur une frise.</p>
    </div>
    ${etapes || `<p class="vide">Aucune étape pour l'instant.</p>`}`;
}

function vueAdminReglages() {
  const d = etat.donnees_admin;
  const membres = (id) => etat.classement.filter((j) => j.equipe_id === id).length;
  return `
    <div class="carte">
      <h2>Réglages du jeu</h2>
      <div class="actions">
        <label class="aide">Capital de départ <input type="number" min="0" step="1" data-k="r-capital" value="${etat.capital}" class="petit"></label>
        <label class="aide">Amorce de la banque, par issue <input type="number" min="0" step="1" data-k="r-amorce" value="${etat.amorce}" class="petit"></label>
        <label class="aide">Bonus quotidien <input type="number" min="0" step="1" data-k="r-bonus" value="${d.bonus}" class="petit"></label>
        <label class="aide">Bonus question du jour <input type="number" min="0" step="1" data-k="r-bonus-question" value="${d.bonus_question}" class="petit"></label>
        <button data-action="admin-reglages">Enregistrer</button>
        <button class="lien" data-action="admin-reglages-defaut">Revenir aux valeurs par défaut
          (${nb(d.capital_defaut)} / ${nb(d.amorce_defaut)} / ${nb(d.bonus_defaut)} / ${nb(d.bonus_question_defaut)})</button>
      </div>
      <ul class="aide">
        <li>Le capital s'applique rétroactivement : changer 1 000 en 1 500 ajoute 500 🪙 à chaque joueur.</li>
        <li>L'amorce change immédiatement les cotes de tous les paris en cours (pas ceux déjà clôturés).
          Plus elle est haute, plus les cotes sont stables ; 0 = pari mutuel pur. C'est aussi l'apport de la
          banque à la cagnotte des paris sur un chiffre.</li>
        <li>Bonus quotidien : récupérable par chaque joueur toutes les 24 heures (0 = désactivé). Bonus question du
          jour : offert à la première mise du jour sur la question du jour, quelle que soit la réponse (0 = désactivé).</li>
        <li>Ces valeurs priment sur les variables GitHub <code>PLF_CAPITAL</code> et <code>PLF_AMORCE</code>.</li>
      </ul>
    </div>
    <div class="carte">
      <h2>Accès au jeu</h2>
      <div class="actions">
        <label class="aide">Code d'invitation <input data-k="r-invitation" maxlength="50" value="${esc(d.code_invitation)}" placeholder="aucun : inscription libre" autocomplete="off"></label>
        <button data-action="admin-invitation">Enregistrer</button>
      </div>
      <p class="aide">${d.code_invitation ? "Inscription réservée aux personnes qui ont ce code (majuscules indifférentes). Les joueurs déjà inscrits ne sont pas concernés."
        : "⚠ Inscription ouverte à toute personne qui connaît l'adresse du site. Un code d'invitation, diffusé aux seuls collègues, évite les inconnus et les comptes multiples."}</p>
    </div>
    <div class="carte">
      <h2>Équipes</h2>
      ${etat.equipes.map((e) => `
        <div class="actions" data-equipe="${e.id}">
          <input data-k="eq-${e.id}" maxlength="60" value="${esc(e.nom)}">
          <span class="aide">${pluriel(membres(e.id), "joueur")}</span>
          <button class="secondaire" data-action="admin-equipe-maj">Renommer</button>
          <button class="lien" data-action="admin-equipe-suppression">Supprimer</button>
        </div>`).join("") || `<p class="aide">Aucune équipe : le classement par équipe est masqué.</p>`}
      <div class="actions">
        <input data-k="eq-nouvelle" maxlength="60" placeholder="Ex. : DG75, Comptes nationaux…">
        <button data-action="admin-equipe-ajout">Ajouter l'équipe</button>
      </div>
      <p class="aide">Les joueurs choisissent leur équipe à l'inscription ou dans « Mes mises » ; vous pouvez aussi la changer dans l'onglet Joueurs.</p>
    </div>
    <div class="carte">
      <h2>Sauvegarde</h2>
      <p><a class="bouton" href="api.php?r=admin/sauvegarde">⬇ Télécharger une sauvegarde complète</a></p>
      <p class="aide">Fichier <code>.db</code> (base SQLite) : pour restaurer, remplacez <code>data/plf.db</code> par ce fichier
        via FTP. Avec MySQL, la sauvegarde est un export JSON de toutes les tables. Conservez-la hors du dépôt GitHub (public).</p>
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
  afficherTypePari();
}

// ---------------------------------------------------------------------------
// Calendrier du PLF
// ---------------------------------------------------------------------------

function vueCalendrier() {
  if (!etat.etapes.length) return `<p class="vide">Le calendrier n'a pas encore été renseigné par l'administration.</p>`;
  const aujourdhui = etat.maintenant.slice(0, 10);
  let repere = false;
  const items = etat.etapes.map((e) => {
    let marque = "";
    if (!repere && e.date >= aujourdhui) {
      repere = true;
      if (e.date > aujourdhui) marque = `<li class="repere-aujourdhui"><span>Aujourd'hui</span></li>`;
    }
    const classe = e.date < aujourdhui ? "passee" : e.date === aujourdhui ? "aujourdhui" : "a-venir";
    const paris = etat.paris.filter((p) => p.etape_id === e.id);
    return `${marque}
      <li class="etape-frise ${classe}">
        <div class="etape-date">${fmtJour(e.date)}${classe === "aujourdhui" ? ` <span class="badge ouvert">aujourd'hui</span>` : ""}</div>
        <div class="etape-corps">
          <h3>${esc(e.titre)}</h3>
          ${e.description ? `<p class="desc">${esc(e.description)}</p>` : ""}
          ${paris.length ? `<div class="puces">${paris.map((p) =>
            `<a class="puce-pari ${p.accepte_mises ? "ouvert" : p.statut}" href="#pari-${p.id}">${esc(p.titre)}</a>`).join("")}</div>`
            : `<p class="aide">Aucun pari rattaché.</p>`}
        </div>
      </li>`;
  }).join("");
  return `<ol class="frise">${items}${repere ? "" : `<li class="repere-aujourdhui"><span>Aujourd'hui</span></li>`}</ol>`;
}

// ---------------------------------------------------------------------------
// Courbes (SVG maison) : escaliers, réticule + info-bulle, légende, étiquettes directes, tableau
// ---------------------------------------------------------------------------

// Palette catégorielle validée (ordre fixe, jamais recyclée) : ≤ 8 séries.
const COULEURS = ["#2a78d6", "#eb6834", "#1baf7a", "#eda100", "#e87ba4", "#008300", "#4a3aa7", "#e34948"];

/** Graduations « rondes » couvrant [min, max]. */
function graduations(min, max, n = 4) {
  if (min === max) { min -= 1; max += 1; }
  const brut = (max - min) / n;
  const puissance = 10 ** Math.floor(Math.log10(brut));
  const pas = [1, 2, 2.5, 5, 10].map((f) => f * puissance).find((p) => p >= brut);
  const debut = Math.floor(min / pas) * pas, fin = Math.ceil(max / pas) * pas;
  const t = [];
  for (let v = debut; v <= fin + pas / 2; v += pas) t.push(Math.round(v * 1e6) / 1e6);
  return t;
}

/**
 * Repères verticaux (dépêches) : étiquettes sur deux rangées pour ne pas se chevaucher, alignées à
 * droite du trait près du bord droit ; au-delà, seul le trait reste (texte complet au survol).
 */
function dessinReperes(reperes, t0, t1, x, W, m, H) {
  const derniers = [-Infinity, -Infinity]; // fin de la dernière étiquette de chaque rangée
  return reperes.map((r) => ({ ...r, px: x(+new Date(r.date)) }))
    .filter((r) => r.px >= m.g && r.px <= W - m.d + 1).sort((a, b) => a.px - b.px)
    .map((r) => {
      const texte = "📰 " + (r.texte.length > 20 ? r.texte.slice(0, 20) + "…" : r.texte);
      const largeur = texte.length * 5.6;
      const aGauche = r.px + 3 + largeur > W; // près du bord droit : texte à gauche du trait
      const debut = aGauche ? r.px - 3 - largeur : r.px + 3;
      const rangee = derniers.findIndex((fin) => debut > fin + 6);
      if (rangee >= 0) derniers[rangee] = debut + largeur;
      return `<line class="repere" x1="${r.px}" x2="${r.px}" y1="${m.h}" y2="${H - m.b}"><title>${esc(r.texte)}</title></line>`
        + (rangee < 0 ? "" : `<text class="repere-texte" x="${aGauche ? r.px - 3 : r.px + 3}" y="${m.h + 10 + rangee * 13}"
            text-anchor="${aGauche ? "end" : "start"}"><title>${esc(r.texte)}</title>${esc(texte)}</text>`);
    }).join("");
}

/** Valeur d'une série en escalier à l'instant t (dernier point ≤ t). */
const valeurA = (points, t) => { let v = null; for (const [d, y] of points) { if (+d <= t) v = y; else break; } return v; };

/**
 * Dessine des courbes en escalier dans « conteneur ».
 * series : [{ nom, points: [[Date, valeur], …] }] (points triés), une couleur par série dans l'ordre.
 */
function graphique(conteneur, series, { format = nb, domaine = null, reperes = [] } = {}) {
  conteneur.textContent = "";
  // Plusieurs points au même instant : seul le dernier compte (sinon, pics verticaux parasites)
  series = series.map((s) => ({ ...s, points: s.points.filter((p, k, t) => k === t.length - 1 || +t[k + 1][0] !== +p[0]) }))
    .filter((s) => s.points.length).slice(0, COULEURS.length);
  if (!series.length) { conteneur.innerHTML = `<p class="vide">Pas encore de données.</p>`; return; }
  // Dessin à la largeur réelle du conteneur : textes toujours à leur taille, même sur mobile
  const W = Math.round(Math.max(300, Math.min(820, conteneur.clientWidth || 640)));
  const H = W < 480 ? 200 : 230, m = { h: 10, d: series.length <= 4 ? (W < 480 ? 74 : 110) : 16, b: 24, g: 48 };
  const tous = series.flatMap((s) => s.points);
  let t0 = Math.min(...tous.map((p) => +p[0]));
  const t1 = Math.max(...tous.map((p) => +p[0]));
  if (t1 - t0 < 10 * 60e3) t0 = t1 - 10 * 60e3; // historique très court : on élargit vers le passé, jamais vers le futur
  const ticks = domaine ? graduations(domaine[0], domaine[1]) : graduations(Math.min(...tous.map((p) => p[1])), Math.max(...tous.map((p) => p[1])));
  const v0 = ticks[0], v1 = ticks[ticks.length - 1];
  const x = (t) => m.g + (t - t0) / (t1 - t0) * (W - m.g - m.d);
  const y = (v) => m.h + (1 - (v - v0) / (v1 - v0)) * (H - m.h - m.b);
  const courte = t1 - t0 < 2 * 86400e3;
  const fmtAxe = (t) => new Date(t).toLocaleString("fr-FR", courte ? { hour: "2-digit", minute: "2-digit" } : { day: "numeric", month: "short" });

  const grille = ticks.map((v) => `<line class="grille" x1="${m.g}" x2="${W - m.d}" y1="${y(v)}" y2="${y(v)}"/>
    <text class="axe" x="${m.g - 6}" y="${y(v) + 4}" text-anchor="end">${esc(format(v))}</text>`).join("");
  const axeX = [t0, (t0 + t1) / 2, t1].map((t, n) => `<text class="axe" x="${x(t)}" y="${H - 6}"
    text-anchor="${["start", "middle", "end"][n]}">${esc(fmtAxe(t))}</text>`).join("");
  const traces = series.map((s, n) => {
    const d = s.points.map(([t, v], k) => k === 0 ? `M${x(+t)},${y(v)}` : `H${x(+t)}V${y(v)}`).join("")
      + `H${x(t1)}`; // prolonge jusqu'au bout de l'axe
    return `<path d="${d}" fill="none" stroke="${COULEURS[n]}" stroke-width="2" stroke-linejoin="round"/>`;
  }).join("");
  // Étiquettes directes en bout de courbe (≤ 4 séries), écartées pour ne pas se chevaucher
  let etiquettes = "";
  if (series.length <= 4) {
    const fins = series.map((s, n) => ({ n, y: y(s.points[s.points.length - 1][1]) })).sort((a, b) => a.y - b.y);
    fins.forEach((f, k) => { if (k && f.y - fins[k - 1].y < 13) f.y = fins[k - 1].y + 13; });
    etiquettes = fins.map((f) => `<line x1="${x(t1) + 2}" x2="${x(t1) + 10}" y1="${f.y}" y2="${f.y}" stroke="${COULEURS[f.n]}" stroke-width="2"/>
      <text class="etiquette" x="${x(t1) + 13}" y="${f.y + 4}">${esc(series[f.n].nom.slice(0, 14))}</text>`).join("");
  }
  const instants = [...new Set(tous.map((p) => +p[0]))].sort((a, b) => a - b);

  conteneur.innerHTML = `
    <div class="legende">${series.map((s, n) => `<span><i style="border-color:${COULEURS[n]}"></i>${esc(s.nom)}</span>`).join("")}</div>
    <div class="zone-graphique">
      <svg viewBox="0 0 ${W} ${H}" role="img" tabindex="0" aria-label="Courbes : ${esc(series.map((s) => s.nom).join(", "))}. Flèches gauche et droite pour parcourir.">
        ${grille}${axeX}${dessinReperes(reperes, t0, t1, x, W, m, H)}
        ${traces}${etiquettes}
        <line class="reticule" y1="${m.h}" y2="${H - m.b}" hidden/>
        <rect class="cible" x="${m.g}" y="0" width="${W - m.g - m.d}" height="${H}" fill="transparent"/>
      </svg>
      <div class="infobulle" hidden></div>
    </div>
    <details class="donnees"><summary>Voir les données</summary><div class="tableau-conteneur"><table>
      <thead><tr><th>Date</th>${series.map((s) => `<th class="nombre">${esc(s.nom)}</th>`).join("")}</tr></thead>
      <tbody>${instants.map((t) => `<tr><td>${esc(fmtDate(new Date(t).toISOString()))}</td>${series.map((s) =>
        `<td class="nombre">${esc(valeurA(s.points, t) == null ? "—" : format(valeurA(s.points, t)))}</td>`).join("")}</tr>`).join("")}</tbody>
    </table></div></details>`;

  const svg = $("svg", conteneur), reticule = $(".reticule", svg), bulle = $(".infobulle", conteneur);
  let courant = instants.length - 1;
  const montrer = (k) => {
    courant = Math.max(0, Math.min(instants.length - 1, k));
    const t = instants[courant], px = x(t);
    reticule.setAttribute("x1", px); reticule.setAttribute("x2", px); reticule.hidden = false;
    bulle.textContent = "";
    const titre = document.createElement("div");
    titre.className = "bulle-date";
    titre.textContent = new Date(t).toLocaleString("fr-FR", { day: "numeric", month: "short", hour: "2-digit", minute: "2-digit" });
    bulle.append(titre);
    series.forEach((s, n) => {
      const ligne = document.createElement("div");
      const cle = document.createElement("i"); cle.style.borderColor = COULEURS[n];
      const valeur = document.createElement("b"); const v = valeurA(s.points, t); valeur.textContent = v == null ? "—" : format(v);
      const nom = document.createElement("span"); nom.textContent = s.nom;
      ligne.append(cle, valeur, nom);
      bulle.append(ligne);
    });
    bulle.hidden = false;
    const largeur = svg.getBoundingClientRect().width || W;
    const gauche = px / W * largeur;
    bulle.style.left = gauche > largeur / 2 ? "" : `${gauche + 12}px`;
    bulle.style.right = gauche > largeur / 2 ? `${largeur - gauche + 12}px` : "";
  };
  const cacher = () => { reticule.hidden = true; bulle.hidden = true; };
  svg.addEventListener("pointermove", (ev) => {
    const r = svg.getBoundingClientRect();
    const t = t0 + ((ev.clientX - r.left) / r.width * W - m.g) / (W - m.g - m.d) * (t1 - t0);
    let k = 0; // instant le plus proche
    instants.forEach((u, n) => { if (Math.abs(u - t) < Math.abs(instants[k] - t)) k = n; });
    montrer(k);
  });
  svg.addEventListener("pointerleave", cacher);
  svg.addEventListener("blur", cacher);
  svg.addEventListener("focus", () => montrer(courant));
  svg.addEventListener("keydown", (ev) => {
    if (ev.key === "ArrowLeft" || ev.key === "ArrowRight") { ev.preventDefault(); montrer(courant + (ev.key === "ArrowRight" ? 1 : -1)); }
  });
}

/** Courbes des cotes des paris dont le panneau « Évolution des cotes » est ouvert. */
function majCourbes() {
  $$("details[data-courbe-pari][open]").forEach(async (d) => {
    const id = d.dataset.courbePari, cible = $(".graphique", d);
    const h = historiquesCotes[id];
    if (!h || (h.v !== etat.v && !h.enCours)) {
      historiquesCotes[id] = { ...h, enCours: true };
      if (!h) cible.innerHTML = `<p class="vide">Chargement…</p>`;
      try {
        historiquesCotes[id] = { v: etat.v, donnees: await api(`historique-cotes&pari=${id}`) };
      } catch (e) {
        historiquesCotes[id] = { v: etat.v, erreur: e.message };
      }
      return majCourbes();
    }
    if ((!h.donnees && !h.erreur) || (cible._v === h.v && cible.childNodes.length)) return;
    cible._v = h.v;
    if (h.erreur) { cible.innerHTML = `<p class="vide">${esc(h.erreur)}</p>`; return; }
    const { issues, points } = h.donnees;
    // Cotes relevées → probabilités implicites, ramenées à 100 % à chaque relevé
    const probas = points.map((p) => {
      const inverses = Object.fromEntries(Object.entries(p.cotes).map(([id, c]) => [id, c ? 1 / c : 0]));
      const somme = Object.values(inverses).reduce((a, b) => a + b, 0);
      return { date: new Date(p.date), p: somme ? Object.fromEntries(Object.entries(inverses).map(([id, v]) => [id, v / somme])) : {} };
    });
    graphique(cible, issues.map((i) => ({
      nom: i.libelle,
      points: probas.filter((x) => x.p[i.id] != null).map((x) => [x.date, x.p[i.id]]),
    })), { format: pourcent, domaine: [0, 1], reperes: etat.depeches });
  });
}

/** Onglet Statistiques : deniers publics des 5 premiers, du joueur connecté et d'un joueur au choix. */
async function majStats() {
  if (onglet !== "stats") return;
  const cible = $("#stats-joueurs");
  if (historiqueJoueurs?.v !== etat.v && !chargementStats) {
    chargementStats = true;
    try {
      historiqueJoueurs = { v: etat.v, donnees: await api("historique-joueurs") };
    } catch (e) {
      cible.innerHTML = `<p class="vide">${esc(e.message)}</p>`;
      return;
    } finally {
      chargementStats = false;
    }
  }
  if (!historiqueJoueurs) return;
  const choisis = new Set(etat.classement.slice(0, 5).map((j) => j.id));
  if (etat.moi) choisis.add(etat.moi.id);
  if (+$("#stats-comparer").value) choisis.add(+$("#stats-comparer").value);
  const cle = JSON.stringify([historiqueJoueurs.v, [...choisis]]);
  if (cible._cle === cle) return;
  cible._cle = cle;
  // ordre alphabétique : la couleur d'un joueur ne dépend pas de son rang
  const series = historiqueJoueurs.donnees.joueurs.filter((j) => choisis.has(j.id))
    .sort((a, b) => a.pseudo.localeCompare(b.pseudo))
    .map((j) => ({ nom: j.pseudo + (etat.moi?.id === j.id ? " (vous)" : ""), points: j.points.map(([d, v]) => [new Date(d), v]) }));
  graphique(cible, series, { format: (v) => nb(v) + " 🪙" });
}

// ---------------------------------------------------------------------------
// Lien direct vers un pari : …/#pari-12
// ---------------------------------------------------------------------------

function suivreAncre() {
  const m = location.hash.match(/^#pari-(\d+)$/);
  if (!m || !etat) return;
  const p = etat.paris.find((p) => p.id === Number(m[1]));
  if (!p) return toast("Ce pari n'existe plus.", "erreur");
  onglet = p.statut === "clos" || p.statut === "annule" ? "clos" : "ouverts";
  rendre();
  const carte = document.getElementById("pari-" + p.id);
  if (!carte) return;
  carte.scrollIntoView({ behavior: "smooth", block: "start" });
  carte.classList.add("surligne");
  setTimeout(() => carte.classList.remove("surligne"), 2500);
}

async function changerEquipe(nom) {
  try {
    const r = await api("profil", { body: { equipe: nom } });
    toast(!r.equipe ? "Vous n'êtes plus dans une équipe." : r.equipe_creee ? `Équipe « ${r.equipe} » créée : vous en êtes le premier membre !`
      : `Vous faites maintenant partie de « ${r.equipe} ».`, "succes");
    const champ = $('[data-k="p-equipe"]');
    if (champ) champ.value = "";
    await rafraichir();
  } catch (e) {
    toast(e.message, "erreur");
  }
}

async function copierLien(id) {
  const url = lienPari(id);
  try {
    await navigator.clipboard.writeText(url);
    toast("Lien copié : collez-le dans un message !", "succes");
  } catch {
    prompt("Copiez ce lien :", url);
  }
}

// ---------------------------------------------------------------------------
// Événements
// ---------------------------------------------------------------------------

document.addEventListener("submit", async (ev) => {
  const form = ev.target;
  ev.preventDefault();

  if (form.id === "form-joueur") {
    await action(api("connexion", { body: { pseudo: form.pseudo.value, pin: form.pin.value } }), "Connecté.");
  } else if (form.id === "form-inscription") {
    const body = { pseudo: form.pseudo.value, pin: form.pin.value, equipe: form.equipe.value };
    if (form.invitation) body.invitation = form.invitation.value;
    try {
      const r = await api("inscription", { body });
      toast(`Bienvenue ! ${nb(etat.capital)} deniers publics vous attendent.`
        + (r.equipe ? (r.equipe_creee ? ` Équipe « ${r.equipe} » créée.` : ` Vous rejoignez « ${r.equipe} ».`) : ""), "succes");
      onglet = "ouverts";
      await rafraichir();
    } catch (e) {
      toast(e.message, "erreur");
    }
  } else if (form.id === "form-profil") {
    await changerEquipe(form.equipe.value);
  } else if (form.classList.contains("form-commentaire")) {
    const champ = form.texte, texte = champ.value;
    champ.value = ""; // vidé avant le rafraîchissement, qui conserve les saisies en cours
    if (!(await action(api("commentaires", { body: { pari_id: Number(form.dataset.pariCommentaire), texte } }), "Commentaire publié."))) {
      const nouveau = $(`[data-k="ct-${form.dataset.pariCommentaire}"]`);
      if (nouveau) nouveau.value = texte;
    }
  } else if (form.classList.contains("miser-estimation")) {
    const montant = parseInt(form.montant.value, 10);
    if (!(montant > 0)) return toast("Indiquez une mise d'au moins 1 denier public.", "erreur");
    const body = { issue_id: Number(form.dataset.issue), montant, estimation: form.estimation.value };
    await action(api("mises", { body }), (r) => `Estimation enregistrée, ${nb(montant)} 🪙 misés !` + avecBonus(r));
  } else if (form.classList.contains("miser")) {
    const input = $("input", form);
    const montant = parseInt(input.value, 10);
    if (!(montant > 0)) return toast("Indiquez une mise d'au moins 1 denier public.", "erreur");
    const cle = input.dataset.k;
    input.value = ""; // vidé avant le rafraîchissement, qui conserve les saisies en cours
    const ok = await action(api("mises", { body: { issue_id: Number(form.dataset.issue), montant } }),
      (r) => `Mise de ${nb(montant)} 🪙 enregistrée !` + avecBonus(r));
    if (!ok) { const champ = $(`[data-k="${cle}"]`); if (champ) champ.value = montant; }
    majGainsPotentiels();
  } else if (form.id === "form-admin") {
    if (await action(api("admin/connexion", { body: { mot_de_passe: $("#admin-mdp").value } }), "Mode administration activé."))
      $("#admin-mdp").value = "";
  } else if (form.id === "form-pari") {
    const type = form.type.value;
    const issues = $$('.ligne-issue [name="libelle"]', form).map((i) => i.value);
    const body = { type, titre: form.titre.value, categorie: form.categorie.value, description: form.description.value,
                   date_limite: form.date_limite.value, etape_id: form.etape_id.value, unite: form.unite.value, issues };
    if (await action(api("paris", { body }), "Pari publié : à vous de miser !")) {
      reinitialiserFormPari();
      onglet = "ouverts";
      rendre();
    }
  }
});

document.addEventListener("click", async (ev) => {
  const ancre = ev.target.closest('a[href^="#pari-"]');
  if (ancre) {
    if ($("#fiche").open) $("#fiche").close(); // même lien cliqué deux fois : hashchange ne se déclencherait pas
    ev.preventDefault();
    history.replaceState(null, "", ancre.getAttribute("href"));
    suivreAncre();
    if (ancre.dataset.issueCible) $(`[data-k="m-${ancre.dataset.issueCible}"]`)?.focus({ preventScroll: true });
    return;
  }
  const lienFiche = ev.target.closest("[data-fiche]");
  if (lienFiche) return ouvrirFiche(lienFiche.dataset.fiche);
  if (ev.target.id === "fiche") return $("#fiche").close(); // clic sur le fond
  const bouton = ev.target.closest("[data-onglet], [data-onglet-admin], [data-classement], [data-action]");
  if (!bouton) return;
  if (bouton.dataset.onglet) {
    onglet = bouton.dataset.onglet;
    return rendre();
  }
  if (bouton.dataset.ongletAdmin) {
    ongletAdmin = bouton.dataset.ongletAdmin;
    return rendre();
  }
  if (bouton.dataset.classement) {
    modeClassement = bouton.dataset.classement;
    return rendre();
  }
  const carte = bouton.closest("[data-pari]");
  const pariId = carte?.dataset.pari;
  const fiche = bouton.closest("[data-joueur]");
  const joueurId = fiche?.dataset.joueur;
  const champ = (k, el = document) => $(`[data-k="${k}"]`, el);
  const pari = etat.paris.find((p) => String(p.id) === pariId);
  switch (bouton.dataset.action) {
    case "deconnexion":
      await action(api("deconnexion", { method: "POST" }));
      break;
    case "copier-lien":
      await copierLien(bouton.dataset.pariLien);
      break;
    case "bonus":
      await action(api("bonus", { method: "POST" }), (r) =>
        `🎁 +${nb(r.montant)} deniers publics !` + (r.serie >= 2 ? ` 🔥 ${r.serie} jours d'affilée.` : "") + " Revenez dans 24 heures.");
      break;
    case "fermer-fiche":
      $("#fiche").close();
      break;
    case "quitter-equipe":
      await changerEquipe("");
      break;
    case "supprimer-commentaire":
      if (!confirm("Supprimer ce commentaire ?")) return;
      await action(api(`commentaires/${bouton.dataset.commentaire}/suppression`, { method: "POST" }), "Commentaire supprimé.");
      break;
    case "admin-flash": {
      const titre = champ("fl-titre");
      const body = { titre: titre.value, minutes: Number(champ("fl-minutes").value),
                     issues: champ("fl-issues").value.split("/").map((i) => i.trim()).filter(Boolean) };
      try {
        const r = await api("admin/flash", { body });
        toast(`⚡ Pari flash lancé : mises jusqu'à ${fmtHeure(r.date_limite)}.`, "succes");
        titre.value = "";
        await rafraichir();
      } catch (e) {
        toast(e.message, "erreur");
      }
      break;
    }
    case "retirer-issue":
      bouton.closest(".ligne-issue").remove();
      break;
    case "admin-maj": {
      const k = `ed-${pariId}`;
      const body = {
        titre: champ(`${k}-titre`, carte).value, categorie: champ(`${k}-cat`, carte).value,
        description: champ(`${k}-desc`, carte).value, date_limite: champ(`${k}-date`, carte).value,
        etape_id: champ(`${k}-etape`, carte).value,
      };
      if (pari.type === "estimation") {
        body.unite = champ(`${k}-unite`, carte).value;
      } else {
        body.issues = Object.fromEntries($$(`.edition [data-issue]`, carte).map((i) => [i.dataset.issue, i.value]));
        body.retirer = $$("[data-retirer]", carte).filter((c) => c.checked).map((c) => Number(c.dataset.retirer));
        body.nouvelles = champ(`${k}-nouvelle`, carte) ? [champ(`${k}-nouvelle`, carte).value] : [];
      }
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
      const joueur = champ(`mj-${pariId}`, carte), montant = champ(`mm-${pariId}`, carte);
      if (!joueur.value) return toast("Choisissez le joueur.", "erreur");
      const body = { joueur_id: Number(joueur.value), montant: parseInt(montant.value, 10) };
      if (pari.type === "estimation") {
        body.issue_id = pari.issues[0].id;
        body.estimation = champ(`me-${pariId}`, carte).value;
      } else {
        body.issue_id = Number(champ(`mi-${pariId}`, carte).value);
      }
      if (await action(api("admin/mises", { body }), `Mise de ${nb(body.montant)} 🪙 enregistrée pour ${pseudoDe(body.joueur_id)}.`)) {
        montant.value = "";
        if (champ(`me-${pariId}`, carte)) champ(`me-${pariId}`, carte).value = "";
      }
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
      const body = { pseudo: champ(`jp-${joueurId}`, fiche).value, pin: code.value };
      if (champ(`je-${joueurId}`, fiche)) body.equipe_id = champ(`je-${joueurId}`, fiche).value;
      if (await action(api(`admin/joueurs/${joueurId}/maj`, { body }), "Joueur mis à jour.")) code.value = "";
      break;
    }
    case "admin-ajustement": {
      const montant = champ(`ja-${joueurId}`, fiche), motif = champ(`jm-${joueurId}`, fiche);
      const body = { montant: parseInt(montant.value, 10), motif: motif.value };
      if (await action(api(`admin/joueurs/${joueurId}/ajustement`, { body }), body.montant > 0 ? "Deniers publics crédités." : "Deniers publics débités.")) {
        montant.value = motif.value = "";
      }
      break;
    }
    case "admin-joueur-suppression":
      if (!confirm(`Supprimer définitivement ${pseudoDe(Number(joueurId))} et toutes ses mises ?`)) return;
      await action(api(`admin/joueurs/${joueurId}/suppression`, { method: "POST" }), "Joueur supprimé.");
      break;
    case "admin-reglages":
      await action(api("admin/reglages", { body: { capital: champ("r-capital").value, amorce: champ("r-amorce").value,
        bonus: champ("r-bonus").value, bonus_question: champ("r-bonus-question").value } }), "Réglages enregistrés.");
      break;
    case "admin-reglages-defaut":
      ["r-capital", "r-amorce", "r-bonus", "r-bonus-question"].forEach((k) => (champ(k).value = ""));
      await action(api("admin/reglages", { body: { capital: "", amorce: "", bonus: "", bonus_question: "" } }), "Valeurs par défaut rétablies.");
      break;
    case "admin-invitation": {
      const code = champ("r-invitation").value.trim();
      await action(api("admin/reglages", { body: { code_invitation: code } }),
        code ? "Code d'invitation enregistré : diffusez-le aux joueurs." : "Inscription ouverte à tous.");
      break;
    }
    case "admin-equipe-ajout": {
      const nom = champ("eq-nouvelle");
      if (await action(api("admin/equipes", { body: { nom: nom.value } }), "Équipe ajoutée.")) nom.value = "";
      break;
    }
    case "admin-equipe-maj": {
      const id = bouton.closest("[data-equipe]").dataset.equipe;
      await action(api(`admin/equipes/${id}/maj`, { body: { nom: champ(`eq-${id}`).value } }), "Équipe renommée.");
      break;
    }
    case "admin-equipe-suppression": {
      const id = bouton.closest("[data-equipe]").dataset.equipe;
      if (!confirm("Supprimer cette équipe ? Ses membres se retrouvent sans équipe (leurs deniers publics ne changent pas).")) return;
      await action(api(`admin/equipes/${id}/suppression`, { method: "POST" }), "Équipe supprimée.");
      break;
    }
    case "admin-etape-ajout": {
      const body = { date: champ("et-nouvelle-date").value, titre: champ("et-nouvelle-titre").value, description: champ("et-nouvelle-desc").value };
      if (await action(api("admin/etapes", { body }), "Étape ajoutée au calendrier.")) {
        ["date", "titre", "desc"].forEach((c) => (champ(`et-nouvelle-${c}`).value = ""));
      }
      break;
    }
    case "admin-etape-maj": {
      const id = bouton.closest("[data-etape]").dataset.etape;
      const body = { date: champ(`et-${id}-date`).value, titre: champ(`et-${id}-titre`).value, description: champ(`et-${id}-desc`).value };
      await action(api(`admin/etapes/${id}/maj`, { body }), "Étape modifiée.");
      break;
    }
    case "admin-etape-suppression": {
      const id = bouton.closest("[data-etape]").dataset.etape;
      if (!confirm("Supprimer cette étape ? Les paris qui y sont rattachés restent, sans étape.")) return;
      await action(api(`admin/etapes/${id}/suppression`, { method: "POST" }), "Étape supprimée.");
      break;
    }
    case "admin-depeche": {
      const texte = champ("dp-texte");
      if (await action(api("admin/depeches", { body: { texte: texte.value } }), "Dépêche publiée.")) texte.value = "";
      break;
    }
    case "admin-depeche-suppression":
      if (!confirm("Supprimer cette dépêche ?")) return;
      await action(api(`admin/depeches/${bouton.dataset.depeche}/suppression`, { method: "POST" }), "Dépêche supprimée.");
      break;
    case "admin-deplacer":
      await action(api(`admin/paris/${pariId}/deplacer`, { body: { sens: bouton.dataset.sens } }));
      break;
    case "admin-pdj-choix": {
      const choix = champ("pdj-choix").value;
      await action(api("admin/pari-du-jour", { body: { pari_id: choix } }),
        choix ? "Question du jour enregistrée, en tête de l'accueil." : "Question du jour : choix automatique.");
      break;
    }
    case "admin-pdj":
      await action(api("admin/pari-du-jour", { body: { pari_id: bouton.dataset.pdj } }),
        bouton.dataset.pdj ? "C'est la question du jour, en tête de l'accueil." : "Question du jour : choix automatique.");
      break;
    case "admin-tout-suspendre":
      if (!confirm("Suspendre immédiatement les mises de tous les paris ouverts ?")) return;
      await action(api("admin/suspension-generale", { body: { action: "suspendre" } }), "Tous les paris ouverts sont suspendus.");
      break;
    case "admin-tout-rouvrir":
      await action(api("admin/suspension-generale", { body: { action: "rouvrir" } }), "Paris rouverts.");
      break;
    case "admin-statut":
      await action(api(`admin/paris/${pariId}/statut`, { body: { statut: bouton.dataset.statut } }));
      break;
    case "admin-cloture": {
      const realiseLe = champ(`rl-${pariId}`, carte).value;
      const body = { realise_le: realiseLe };
      let resultat;
      if (pari.type === "estimation") {
        body.valeur = champ(`vr-${pariId}`, carte).value.trim();
        if (!body.valeur) return toast("Indiquez d'abord la valeur réelle.", "erreur");
        resultat = `la valeur réelle ${body.valeur}${pari.unite ? " " + pari.unite : ""}`;
      } else {
        const select = champ(`w-${pariId}`, carte);
        if (!select.value) return toast("Choisissez d'abord l'issue réalisée.", "erreur");
        body.issue_id = Number(select.value);
        resultat = `l'issue « ${select.selectedOptions[0].textContent.trim()} »`;
      }
      if (!confirm(`Clôturer ce pari avec ${resultat} ? `
        + (realiseLe ? `Les mises placées depuis le ${fmtDate(realiseLe)} seront remboursées. ` : "Aucune heure de résultat indiquée : toutes les mises comptent. ")
        + "La cagnotte sera partagée immédiatement.")) return;
      try {
        const r = await api(`admin/paris/${pariId}/cloture`, { body });
        toast("Pari clôturé, gains versés." + (r.nb_tardives ? ` ${pluriel(r.nb_tardives, "mise tardive")} remboursée${r.nb_tardives > 1 ? "s" : ""}.` : ""), "succes");
        await rafraichir();
      } catch (e) {
        toast(e.message, "erreur");
      }
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

document.addEventListener("change", async (ev) => {
  if (ev.target.id === "stats-comparer") {
    majStats();
  } else if (ev.target.name === "type" && ev.target.closest("#form-pari")) {
    afficherTypePari();
  }
});

// L'événement « toggle » ne remonte pas : on l'écoute en phase de capture.
document.addEventListener("toggle", (ev) => {
  if (ev.target.matches?.("details[data-courbe-pari]") && ev.target.open) majCourbes();
}, true);

function afficherTypePari() {
  const estimation = $("#form-pari").type.value === "estimation";
  $("#form-pari-issues").hidden = estimation;
  $("#form-pari-unite").hidden = !estimation;
}

$("#ajout-issue").addEventListener("click", () => ajouterLigneIssue());
$("#admin-deconnexion").addEventListener("click", () => action(api("admin/deconnexion", { method: "POST" })));

// Accès à l'administration via l'URL …/#admin, au chargement ou en cours de visite ; lien direct …/#pari-12
if (location.hash === "#admin") onglet = "admin";
window.addEventListener("hashchange", () => {
  if (location.hash === "#admin") {
    onglet = "admin";
    if (etat) rendre();
  } else {
    suivreAncre();
  }
});

reinitialiserFormPari();
rafraichir();
setInterval(() => { if (!document.hidden) rafraichir(); }, RAFRAICHISSEMENT_MS);
setInterval(majComptesARebours, 1000);
document.addEventListener("visibilitychange", () => { if (!document.hidden) rafraichir(); });

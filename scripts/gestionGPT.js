var prompt_base = "";
var NOMBRE_TOKENS_A_AFFICHER = 5;

function highlightText() {
    var textarea = document.getElementById("prompt");
    var start = prompt_base.length;
    var end = textarea.value.length;

    textarea.setSelectionRange(start, end);
    textarea.focus();
}

function efface_resultat() {
    document.getElementById("prompt").value = prompt_base;
    cacheVue("probabilités");
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
}

function formatToken(token) {
    if (token === "") {
        return "<em>token vide</em>";
    }

    return escapeHtml(token)
        .replace(/ /g, "&nbsp;")
        .replace(/\n/g, "↵<br>")
        .replace(/\t/g, "→&nbsp;&nbsp;");
}

function logprobToPercent(logprob) {
    var value = Number(logprob);

    if (!Number.isFinite(value)) {
        return null;
    }

    return 100 * Math.exp(value);
}

function formatProbability(logprob) {
    var probability = logprobToPercent(logprob);

    if (probability === null) {
        return null;
    }

    if (probability >= 10) {
        return probability.toFixed(1).replace(".", ",") + "%";
    }

    if (probability >= 1) {
        return probability.toFixed(2).replace(".", ",") + "%";
    }

    if (probability >= 0.01) {
        return probability.toFixed(3).replace(".", ",") + "%";
    }

    return "< 0,01%";
}

function isCandidateObject(value) {
    return value
        && typeof value === "object"
        && !Array.isArray(value)
        && typeof value.token === "string"
        && Number.isFinite(Number(value.logprob));
}

/**
 * Convertit une collection d'alternatives en tableau sans fusionner les tokens.
 * Together AI peut renvoyer soit une liste d'objets {token, logprob}, soit un
 * objet dont les clés sont les tokens et les valeurs leurs log-probabilités.
 */
function alternativesToArray(alternatives) {
    if (!alternatives) {
        return [];
    }

    if (Array.isArray(alternatives)) {
        var arrayResult = [];

        alternatives.forEach(function (candidate) {
            if (isCandidateObject(candidate)) {
                arrayResult.push({
                    token: candidate.token,
                    logprob: Number(candidate.logprob),
                    tokenId: candidate.token_id ?? candidate.tokenId ?? null,
                    bytes: Array.isArray(candidate.bytes) ? candidate.bytes.slice() : null
                });
                return;
            }

            if (candidate && typeof candidate === "object" && !Array.isArray(candidate)) {
                arrayResult = arrayResult.concat(alternativesToArray(candidate));
            }
        });

        return arrayResult;
    }

    if (isCandidateObject(alternatives)) {
        return [{
            token: alternatives.token,
            logprob: Number(alternatives.logprob),
            tokenId: alternatives.token_id ?? alternatives.tokenId ?? null,
            bytes: Array.isArray(alternatives.bytes) ? alternatives.bytes.slice() : null
        }];
    }

    if (typeof alternatives !== "object") {
        return [];
    }

    var objectResult = [];

    Object.keys(alternatives).forEach(function (token) {
        var value = alternatives[token];

        if (Number.isFinite(Number(value))) {
            objectResult.push({
                token: token,
                logprob: Number(value),
                tokenId: null,
                bytes: null
            });
            return;
        }

        if (value && typeof value === "object") {
            var candidateToken = typeof value.token === "string" ? value.token : token;
            var candidateLogprob = value.logprob ?? value.log_prob;

            if (Number.isFinite(Number(candidateLogprob))) {
                objectResult.push({
                    token: candidateToken,
                    logprob: Number(candidateLogprob),
                    tokenId: value.token_id ?? value.tokenId ?? null,
                    bytes: Array.isArray(value.bytes) ? value.bytes.slice() : null
                });
            }
        }
    });

    return objectResult;
}

/**
 * Normalise les structures de logprobs rencontrées dans :
 * - l'ancien endpoint OpenAI /v1/completions ;
 * - Together AI /v1/chat/completions ;
 * - les réponses modernes de type chat, à titre de compatibilité défensive.
 */
function normaliseLogprobs(choice) {
    var logprobs = choice && choice.logprobs ? choice.logprobs : {};

    if (Array.isArray(logprobs.content)) {
        return {
            tokens: logprobs.content.map(function (item) {
                return item.token;
            }),
            tokenLogprobs: logprobs.content.map(function (item) {
                return item.logprob;
            }),
            tokenBytes: logprobs.content.map(function (item) {
                return Array.isArray(item.bytes) ? item.bytes.slice() : null;
            }),
            topLogprobs: logprobs.content.map(function (item) {
                return alternativesToArray(item.top_logprobs);
            })
        };
    }

    var tokens = Array.isArray(logprobs.tokens) ? logprobs.tokens : [];
    var tokenLogprobs = Array.isArray(logprobs.token_logprobs)
        ? logprobs.token_logprobs
        : [];
    var rawTopLogprobs = logprobs.top_logprobs;
    var topLogprobs = [];

    if (Array.isArray(rawTopLogprobs)) {
        // Structure habituelle de /v1/completions : une collection par token.
        // Une liste directe de candidats est également acceptée par sécurité.
        if (rawTopLogprobs.length > 0 && rawTopLogprobs.every(isCandidateObject)) {
            topLogprobs = [alternativesToArray(rawTopLogprobs)];
        } else {
            topLogprobs = rawTopLogprobs.map(alternativesToArray);
        }
    } else if (rawTopLogprobs && typeof rawTopLogprobs === "object") {
        var keys = Object.keys(rawTopLogprobs);
        var indexedStructure = keys.length > 0 && keys.every(function (key) {
            return /^\d+$/.test(key)
                && rawTopLogprobs[key]
                && typeof rawTopLogprobs[key] === "object";
        });

        if (indexedStructure) {
            keys.sort(function (a, b) {
                return Number(a) - Number(b);
            });
            topLogprobs = keys.map(function (key) {
                return alternativesToArray(rawTopLogprobs[key]);
            });
        } else {
            topLogprobs = [alternativesToArray(rawTopLogprobs)];
        }
    }

    return {
        tokens: tokens,
        tokenLogprobs: tokenLogprobs,
        tokenBytes: [],
        topLogprobs: topLogprobs
    };
}

function extractErrorMessage(responseText, status) {
    if (responseText) {
        try {
            var errorJson = JSON.parse(responseText);
            var apiMessage = errorJson && errorJson.error
                ? errorJson.error.message
                : errorJson.message;

            if (apiMessage) {
                return apiMessage;
            }
        } catch (error) {
            // La réponse n'était pas du JSON ; le message générique est utilisé.
        }
    }

    if (status === 408) {
        return "La requête a dépassé le délai maximal autorisé.";
    }

    if (status === 0) {
        return "Impossible de contacter le serveur.";
    }

    return "Une erreur est survenue lors de l'appel au modèle (HTTP " + status + ").";
}

function candidateKey(candidate) {
    if (Array.isArray(candidate.bytes)) {
        return "bytes:" + candidate.bytes.join(",");
    }

    return "token:" + candidate.token;
}

/**
 * Construit exactement cinq lignes lorsque l'API fournit cinq candidats.
 * Les candidats restent classés par probabilité décroissante et le token
 * effectivement tiré est signalé en gras à son rang réel.
 *
 * L'endpoint de chat renvoie une liste top_logprobs indépendante pour chaque
 * position. Le nombre de lignes ne dépend donc plus du rang du token tiré.
 */
function selectDisplayedCandidates(logprobs, tokenIndex) {
    var chosenToken = logprobs.tokens[tokenIndex];
    var chosenLogprob = Number(logprobs.tokenLogprobs[tokenIndex]);
    var chosenBytes = Array.isArray(logprobs.tokenBytes && logprobs.tokenBytes[tokenIndex])
        ? logprobs.tokenBytes[tokenIndex].slice()
        : null;
    var alternatives = Array.isArray(logprobs.topLogprobs[tokenIndex])
        ? logprobs.topLogprobs[tokenIndex].slice()
        : [];
    var chosenCandidate = null;
    var candidates = [];
    var seen = new Set();

    if (typeof chosenToken === "string" && Number.isFinite(chosenLogprob)) {
        chosenCandidate = {
            token: chosenToken,
            logprob: chosenLogprob,
            tokenId: null,
            bytes: chosenBytes,
            chosen: true
        };
    }

    alternatives
        .filter(function (candidate) {
            return candidate
                && typeof candidate.token === "string"
                && Number.isFinite(Number(candidate.logprob));
        })
        .sort(function (a, b) {
            return Number(b.logprob) - Number(a.logprob);
        })
        .forEach(function (candidate) {
            var normalizedCandidate = {
                token: candidate.token,
                logprob: Number(candidate.logprob),
                tokenId: candidate.tokenId ?? null,
                bytes: Array.isArray(candidate.bytes) ? candidate.bytes.slice() : null,
                chosen: false
            };
            var key = candidateKey(normalizedCandidate);

            if (seen.has(key)) {
                return;
            }

            if (chosenCandidate && key === candidateKey(chosenCandidate)) {
                normalizedCandidate.chosen = true;
                // La logprob associée à l'échantillon est la valeur de référence.
                normalizedCandidate.logprob = chosenCandidate.logprob;
            }

            candidates.push(normalizedCandidate);
            seen.add(key);
        });

    // L'API inclut normalement le token tiré dans top_logprobs. Ce repli le
    // conserve néanmoins si un moteur compatible ne le fournit pas.
    if (chosenCandidate && !seen.has(candidateKey(chosenCandidate))) {
        candidates.push(chosenCandidate);
    }

    candidates.sort(function (a, b) {
        return Number(b.logprob) - Number(a.logprob);
    });

    var displayed = candidates.slice(0, NOMBRE_TOKENS_A_AFFICHER);

    // Si le token tiré est hors du top 5, afficher les quatre meilleurs tokens
    // et le token tiré, puis restaurer l'ordre probabiliste.
    if (chosenCandidate && !displayed.some(function (candidate) {
        return candidate.chosen;
    })) {
        displayed = candidates
            .filter(function (candidate) {
                return !candidate.chosen;
            })
            .slice(0, NOMBRE_TOKENS_A_AFFICHER - 1)
            .concat([chosenCandidate])
            .sort(function (a, b) {
                return Number(b.logprob) - Number(a.logprob);
            });
    }

    return displayed;
}

var probabilityTreeRedrawTimer = null;

function getProbabilityTreeTokenCount(logprobs) {
    return Math.max(
        Array.isArray(logprobs.tokens) ? logprobs.tokens.length : 0,
        Array.isArray(logprobs.topLogprobs) ? logprobs.topLogprobs.length : 0
    );
}

function probabilityBarWidth(logprob) {
    var probability = logprobToPercent(logprob);

    if (probability === null) {
        return 0;
    }

    return Math.max(0, Math.min(100, probability));
}

function renderProbabilityTreeNode(candidate, tokenIndex, candidateIndex) {
    var probability = formatProbability(candidate.logprob);
    var classes = "token-tree-node" + (candidate.chosen ? " is-chosen" : "");
    var chosenLabel = candidate.chosen
        ? '<span class="token-tree-chosen-label">choisi</span>'
        : "";

    return ''
        + '<div class="' + classes + '"'
        + ' data-tree-node="token-' + tokenIndex + '-' + candidateIndex + '"'
        + ' data-token-index="' + tokenIndex + '"'
        + ' data-candidate-index="' + candidateIndex + '">'
        + '  <div class="token-tree-node-content">'
        + '    <span class="token-tree-token">' + formatToken(candidate.token) + '</span>'
        + '    <span class="token-tree-probability">' + (probability || "—") + '</span>'
        + '  </div>'
        + '  <span class="token-tree-probability-bar" aria-hidden="true">'
        + '    <span style="width: ' + probabilityBarWidth(candidate.logprob).toFixed(4) + '%;"></span>'
        + '  </span>'
        + chosenLabel
        + '</div>';
}

/**
 * Construit un arbre probabiliste horizontal.
 * Chaque niveau présente les cinq candidats d'une position. Seul le candidat
 * effectivement choisi sert de parent au niveau suivant.
 */
function renderProbabilityTree(logprobs) {
    var output = document.getElementById("output_arbre_tokens");

    if (!output) {
        return;
    }

    var tokenCount = getProbabilityTreeTokenCount(logprobs);
    var levels = [];

    for (var tokenIndex = 0; tokenIndex < tokenCount; tokenIndex++) {
        var candidates = selectDisplayedCandidates(logprobs, tokenIndex);

        if (candidates.length === 0) {
            break;
        }

        levels.push(candidates);
    }

    if (levels.length === 0) {
        output.innerHTML = '<p><em>Arrêt du modèle ou probabilités indisponibles.</em></p>';
        return;
    }

    var html = ''
        + '<div class="token-tree-scroll" tabindex="0" aria-label="Arbre des probabilités des tokens">'
        + '  <div class="token-tree-canvas" id="token_tree_canvas">'
        + '    <svg class="token-tree-links" id="token_tree_links" aria-hidden="true"></svg>'
        + '    <div class="token-tree-root" data-tree-node="root">'
        + '      <span class="token-tree-root-title">Contexte</span>'
        + '      <span class="token-tree-root-subtitle">Début de la complétion</span>'
        + '    </div>';

    levels.forEach(function (candidates, tokenIndex) {
        html += ''
            + '<section class="token-tree-level" data-token-level="' + tokenIndex + '">'
            + '  <h6>Token ' + (tokenIndex + 1) + '</h6>'
            + '  <div class="token-tree-candidates">';

        candidates.forEach(function (candidate, candidateIndex) {
            html += renderProbabilityTreeNode(candidate, tokenIndex, candidateIndex);
        });

        if (candidates.length < NOMBRE_TOKENS_A_AFFICHER) {
            html += '<p class="token-tree-warning"><small>'
                + candidates.length
                + ' candidat(s) exploitable(s) renvoyé(s) par l’API.</small></p>';
        }

        html += '  </div></section>';
    });

    html += '  </div></div>';
    output.innerHTML = html;

    scheduleProbabilityTreeRedraw();
}

function createProbabilityTreePath(parentNode, childNode, canvasRect, selected) {
    var parentRect = parentNode.getBoundingClientRect();
    var childRect = childNode.getBoundingClientRect();
    var x1 = parentRect.right - canvasRect.left;
    var y1 = parentRect.top + parentRect.height / 2 - canvasRect.top;
    var x2 = childRect.left - canvasRect.left;
    var y2 = childRect.top + childRect.height / 2 - canvasRect.top;
    var controlDistance = Math.max(28, (x2 - x1) * 0.46);
    var path = document.createElementNS("http://www.w3.org/2000/svg", "path");

    path.setAttribute(
        "d",
        "M " + x1 + " " + y1
        + " C " + (x1 + controlDistance) + " " + y1
        + ", " + (x2 - controlDistance) + " " + y2
        + ", " + x2 + " " + y2
    );
    path.setAttribute("class", selected ? "token-tree-link is-selected" : "token-tree-link");

    return path;
}

/**
 * Dessine les branches après la mise en page effective du navigateur.
 * Cette fonction est aussi appelée lorsque la vue cachée devient visible.
 */
function redessineArbreProbabilites() {
    var canvas = document.getElementById("token_tree_canvas");
    var svg = document.getElementById("token_tree_links");

    if (!canvas || !svg || canvas.offsetWidth === 0 || canvas.offsetHeight === 0) {
        return;
    }

    var canvasRect = canvas.getBoundingClientRect();
    var width = canvas.scrollWidth;
    var height = canvas.scrollHeight;

    svg.setAttribute("width", width);
    svg.setAttribute("height", height);
    svg.setAttribute("viewBox", "0 0 " + width + " " + height);
    svg.innerHTML = "";

    var levels = Array.from(canvas.querySelectorAll(".token-tree-level"));
    var parentNode = canvas.querySelector('[data-tree-node="root"]');

    levels.forEach(function (level) {
        var children = Array.from(level.querySelectorAll(".token-tree-node"));

        if (!parentNode || children.length === 0) {
            return;
        }

        children.forEach(function (childNode) {
            svg.appendChild(
                createProbabilityTreePath(
                    parentNode,
                    childNode,
                    canvasRect,
                    childNode.classList.contains("is-chosen")
                )
            );
        });

        parentNode = level.querySelector(".token-tree-node.is-chosen");
    });
}

function scheduleProbabilityTreeRedraw() {
    if (probabilityTreeRedrawTimer !== null) {
        window.clearTimeout(probabilityTreeRedrawTimer);
    }

    window.requestAnimationFrame(redessineArbreProbabilites);
    probabilityTreeRedrawTimer = window.setTimeout(function () {
        redessineArbreProbabilites();
        probabilityTreeRedrawTimer = null;
    }, 120);
}

window.addEventListener("resize", scheduleProbabilityTreeRedraw);

function start(relance) {
    if (!relance) {
        prompt_base = document.getElementById("prompt").value;
    } else {
        document.getElementById("prompt").value = prompt_base;
    }

    var prompt = prompt_base;

    if (prompt.trim() === "") {
        alert("Veuillez commencer une phrase avant de soumettre le prompt.");
        return;
    }

    afficheVue("waiting");

    var params_php = {
        prompt: prompt
    };

    appel_php_async(
        "php/appelGPT.php",
        JSON.stringify(params_php),
        function (reponse_GPT_json) {
            try {
                var oJson = JSON.parse(reponse_GPT_json);

                if (oJson.error) {
                    throw new Error(oJson.error.message || "Erreur retournée par le modèle.");
                }

                if (!Array.isArray(oJson.choices) || !oJson.choices[0]) {
                    throw new Error("La réponse du modèle ne contient aucune complétion.");
                }

                var choice = oJson.choices[0];
                var reponseGPT_str = typeof choice.text === "string"
                    ? choice.text
                    : (choice.message && typeof choice.message.content === "string"
                        ? choice.message.content
                        : "");

                if (reponseGPT_str === "") {
                    throw new Error("Le modèle n'a généré aucun texte.");
                }

                document.getElementById("prompt").value += reponseGPT_str;
                highlightText();

                var logprobs = normaliseLogprobs(choice);
                renderProbabilityTree(logprobs);

                afficheVue("ready");
                afficheVue("probabilités");
            } catch (error) {
                afficheVue("ready");
                cacheVue("probabilités");
                alert(error.message || "Réponse invalide du serveur.");
            }
        },
        function (responseText, status) {
            afficheVue("ready");
            cacheVue("probabilités");

            if (status === 401) {
                localStorage.removeItem("user_uuid");
                initialisePage();
            }

            alert(extractErrorMessage(responseText, status));
        }
    );
}

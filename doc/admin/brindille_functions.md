Title: Référence des fonctions Brindille

{{{.nav
* [Documentation Brindille](brindille.html)
* **[Fonctions](brindille_functions.html)**
* [Sections](brindille_sections.html)
* [Filtres](brindille_modifiers.html)
}}}

<<toc aside>>

# Fonctions généralistes

## assign

Permet d'assigner une valeur dans une variable.

| Paramètre | Optionnel / obligatoire ? | Fonction |
| :- | :- | :- |
| `.` | optionnel | Assigner toutes les variables du contexte (section) actuel |
| `var` | optionnel | Nom de la variable à créer ou modifier |
| `value` | optionnel | Valeur de la variable |
| `from` | optionnel | Recopier la valeur depuis la variable ayant le nom fourni dans ce paramètre. |

Tous les autres paramètres sont considérés comme des variables à assigner.

Exemple :

```
{{:assign blabla="Coucou"}}

{{$blabla}}
```

Il est possible d'assigner toutes les variables d'une section dans une variable en utilisant le paramètre point `.` (`.="nom_de_variable"`). Cela permet de capturer le contenu d'une section pour le réutiliser à un autre endroit.

```
{{#pages uri="Informations" limit=1}}
{{:assign .="infos"}}
{{/pages}}

{{$infos.title}}
```

Il est aussi possible de remonter dans les sections parentes en utilisant plusieurs points. Ainsi deux points remonteront à la section parente, trois points à la section parente de la section parente, etc.

```
{{#foreach from=$infos item="info"}}
  {{#foreach from=$info item="sous_info"}}
    {{if $sous_info.titre == 'Coucou'}}
      {{:assign ..="info_importante"}}
    {{/if}}
  {{/foreach}}
{{/foreach}}

{{$info_importante.titre}}
```

En utilisant le paramètre spécial `var`, tous les autres paramètres passés sont ajoutés à la variable donnée en valeur :

```
{{:assign var="tableau" label="Coucou" name="Pif le chien"}}
{{$tableau.label}}
{{$tableau.name}}
```

De la même manière on peut écraser une variable avec le paramètre spécial `value`:

```
{{:assign var="tableau" value=$infos}}
```

Il est également possible de créer des tableaux avec la syntaxe `.` dans le nom de la variable :

```
{{:assign var="liste.comptes.530" label="Caisse"}}
{{:assign var="liste.comptes.512" label="Banque"}}

{{#foreach from=$liste.comptes}}
{{$key}} = {{$value.label}}
{{/foreach}}
```

Il est possible de rajouter des éléments à un tableau simplement en utilisant un point seul :

```
{{:assign var="liste.comptes." label="530 - Caisse"}}
{{:assign var="liste.comptes." label="512 - Banque"}}
```

Enfin, il est possible de faire référence à une variable de manière dynamique en utilisant le paramètre spécial `from` :

```
{{:assign var="tableau" a="Coucou" b="Test !"}}
{{:assign var="titre" from="tableau.%s"|args:"b"}}
{{$titre}} -> Affichera "Test !", soit la valeur de {{$tableau.b}}
```

## debug

Cette fonction permet d'afficher le contenu d'une ou plusieurs variables :

```
{{:debug test=$title}}
```

Affichera :

```
array(1) {
  ["test"] => string(6) "coucou"
}
```

Si aucun paramètre n'est spécifié, alors toutes les variables définies sont renvoyées. Utile pour découvrir quelles sont les variables accessibles dans une section par exemple.


## error

Affiche un message d'erreur et arrête le traitement à cet endroit.

| Paramètre | Optionnel / obligatoire ? | Fonction |
| :- | :- | :- |
| `message` | **obligatoire** | Message d'erreur à afficher |

Exemple :

```
{{if $_POST.nombre != 42}}
	{{:error message="Le nombre indiqué n'est pas 42"}}
{{/if}}
```

## http

Permet de modifier les entêtes HTTP renvoyés par la page. Cette fonction doit être appelée au tout début du squelette, avant tout autre code ou ligne vide.

| Paramètre | Optionnel / obligatoire ? | Fonction |
| :- | :- | :- |
| `code` | *optionnel* | Modifie le code HTTP renvoyé. [Liste des codes HTTP](https://fr.wikipedia.org/wiki/Liste_des_codes_HTTP) |
| `redirect` | *optionnel* | Rediriger vers l'adresse URI indiquée en valeur. Seules les adresses internes sont acceptées, il n'est pas possible de rediriger vers une adresse extérieure. |
| `type` | *optionnel* | Modifie le type MIME renvoyé |
| `download` | *optionnel* | Force la page à être téléchargée sous le nom indiqué. |

Note : si le type `application/pdf` est indiqué, la page sera convertie en PDF à la volée. Il est possible de forcer le téléchargement du fichier en utilisant le paramètre `download`.

Exemples :

```
{{:http code=404}}
{{:http redirect="/Nos-Activites/"}}
{{:http type="application/svg+xml"}}
{{:http type="application/pdf" download="liste_membres_ca.pdf"}}
```

## include

Permet d'inclure un autre squelette.

Paramètres :

| Paramètre | Optionnel / obligatoire ? | Fonction |
| :- | :- | :- |
| `file` | **obligatoire** | Nom du squelette à inclure |
| `keep` | *optionnel* | Liste de noms de variables à conserver |
| `capture` | *optionnel* | Si renseigné, au lieu d'afficher le squelette, son contenu sera enregistré dans la variable de ce nom. |
| … | *optionnel* | Tout autre paramètre sera utilisé comme variable qui n'existea qu'à l'intérieur du squelette inclus. |

```
{{* Affiche le contenu du squelette "navigation.html" dans le même répertoire que le squelette d'origine *}}
{{:include file="./navigation.html"}}
```

Par défaut, les variables du squelette parent sont transmis au squelette inclus, mais les variables définies dans le squelette inclus ne sont pas transmises au squelette parent. Exemple :

```
{{* Squelette page.html *}}
{{:assign title="Super titre !"}}
{{:include file="./_head.html"}}
{{$nav}}
```
```
{{* Squelette _head.html *}}
<h1>{{$title}}</h1>
{{:assign nav="Accueil > %s"|args:$title}}
```

Dans ce cas, la dernière ligne du premier squelette (`{{$nav}}`) n'affichera rien, car la variable définie dans le second squelette n'en sortira pas. Pour indiquer qu'une variable doit être transmise au squelette parent, il faut utiliser le paramètre `keep`:

```
{{:include file="./_head.html" keep="nav"}}
```

On peut spécifier plusieurs noms de variables, séparés par des virgules, et utiliser la notation à points :

```
{{:include file="./_head.html" keep="nav,article.title,name"}}
{{$nav}}
{{$article.title}}
{{$name}}
```

On peut aussi capturer le résultat d'un squelette dans une variable :

```
{{:include file="./_test.html" capture="test"}}
{{:assign var="test" value=$test|replace:'TITRE':'Ceci est un titre'}}
{{$test}}
```

Il est possible d'assigner de nouvelles variables au contexte du include en les déclarant comme paramètres tout comme on le ferait avec `{{:assign}}` :

```
{{:include file="./_head.html" title='%s documentation'|args:$doc.label visitor=$user}}
```

## captcha

Permet de générer une question qui doit être répondue correctement par l'utilisateur pour valider une action. Utile pour empêcher les robots spammeurs d'effectuer une action.

L'utilisation simplifiée utilise un de ces deux paramètres :

| Paramètre | Fonction |
| :- | :- |
| `html` | Si `true`, crée un élément de formulaire HTML et le texte demandant à l'utilisateur de répondre à la question |
| `verify` | Si `true`, vérifie que l'utilisateur a correctement répondu à la question |

L'utilisation avancée utilise d'abord ces deux paramètres :

| Paramètre | Fonction |
| :- | :- |
| `assign_hash` | Nom de la variable où assigner le hash (à mettre dans un `<input type="hidden" />`) |
| `assign_number` | Nom de la variable où assigner le nombre de la question (à afficher à l'utilisateur) |

Puis on vérifie :

| Paramètre | Fonction |
| :- | :- |
| `verify_hash` | Valeur qui servira comme hash de vérification (valeur du `<input type="hidden" />`) |
| `verify_number` | Valeur qui représente la réponse de l'utilisateur |
| `assign_error` | Si spécifié, le message d'erreur sera placé dans cette variable, sinon il sera affiché directement. |

Exemple :

```
{{if $_POST.send}}
  {{:captcha verify_hash=$_POST.h verify_number=$_POST.n assign_error="error"}}
  {{if $error}}
    <p class="alert">Mauvaise réponse</p>
  {{else}}
    ...
  {{/if}}
{{/if}}

<form method="post" action="">
{{:captcha assign_hash="hash" assign_number="number"}}
<p>Merci de recopier le nombre suivant en chiffres : <tt>{{$number}}</tt></p>
<p>
  <input type="text" name="n" placeholder="1234" />
  <input type="hidden" name="h" value="{{$hash}}" />
  <input type="submit" name="send" />
</p>
</form>
```

## mail

Permet d'envoyer un e-mail à une ou des adresses indiquées (sous forme de tableau).

Restrictions :

* le message est toujours envoyé en format texte ;
* l'expéditeur est toujours l'adresse de l'association ;
* l'envoi est limité à une seule adresse e-mail externe (adresse qui n'est pas celle d'un membre) dans une page ;
* l'envoi est limité à maximum 10 adresses e-mails internes (adresses de membres) dans une page ;
* un message envoyé à une adresse e-mail externe ne peut pas contenir une adresse web (`https://...`) autre que celle de l'association.

Note : il est également conseillé d'utiliser la fonction `captcha` pour empêcher l'envoi de spam.

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `to` | **obligatoire** | Adresse email destinataire (seule l'adresse e-mail elle-même est acceptée, pas de nom) |
| `subject` | **obligatoire** | Sujet du message |
| `body` | **obligatoire** | Corps du message |
| `block_urls` | *optionnel* | (`true` ou `false`) Permet de bloquer l'envoi si le message contient une adresse `https://…` |

Pour le destinataire, il est possible de spécifier un tableau :

```
{{:assign var="recipients[]" value="membre1@framasoft.net"}}
{{:assign var="recipients[]" value="membre2@chatons.org"}}
{{:mail to=$recipients subject="Coucou" body="Contenu du message\nNouvelle ligne"}}
```

Exemple de formulaire de contact :

```
{{if !$_POST.email|check_email}}
  <p class="alert">L'adresse e-mail indiquée est invalide.</p>
{{elseif $_POST.message|trim == ''}}
  <p class="alert">Le message est vide</p>
{{elseif $_POST.send}}
  {{:captcha verify=true}}
  {{:mail to=$config.org_email subject="Formulaire de contact" body="%s a écrit :\n\n%s"|args:$_POST.email:$_POST.message block_urls=true}}
  <p class="ok">Votre message nous a bien été transmis !</p>
{{/if}}

<form method="post" action="">
<dl>
  <dt><label>Votre e-mail : <input type="email" required name="email" /></label></dt>
  <dt><label>Votre message : <textarea required name="message" cols="50" rows="5"></textarea></label></dt>
  <dt>{{:captcha html=true}}</dt>
</dl>
<p><input type="submit" name="send" value="Envoyer !" /></p>
</form>
```

# Fonctions relatives aux Modules

## save

Enregistre des données, sous la forme d'un document, dans la base de données, pour le module courant.

Note : un appel à cette fonction depuis le code du site web provoquera une erreur, elle ne peut être appelée que depuis un module.

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `key` | optionnel | Clé unique du document |
| `id` | optionnel | Numéro unique du document |
| `validate_schema` | optionnel | Fichier de schéma JSON à utiliser pour valider les données avant enregistrement |
| `assign_new_id` | optionnel | Si renseigné, le nouveau numéro unique du document sera indiqué dans cette variable. |
| … | optionnel | Autres paramètres : traités comme des valeurs à enregistrer dans le document |

Si ni `key` ni `id` ne sont indiqués, un nouveau document sera créé avec un nouveau numéro unique.

Si le document indiqué existe déjà, il sera mis à jour. Les valeurs nulles (`NULL`) seront effacées.

```
{{:save key="facture_43" nom="Atelier mobile" montant=250}}
```

Enregistrera dans la base de données le document suivant sous la clé `facture_43` :

```
{"nom": "Atelier mobile", "montant": 250}
```

Exemple de mise à jour :

```
{{:save key="facture_43" montant=300}}
```

Exemple de récupération du nouvel ID :

```
{{:save titre="Coucou !" assign_new_id="id"}}
Le document n°{{$id}} a bien été enregistré.
```

## delete

Supprime un document lié au module courant.

Note : un appel à cette fonction depuis le code du site web provoquera une erreur, elle ne peut être appelée que depuis un module.

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `key` | optionnel | Clé unique du document |
| `id` | optionnel | Numéro unique du document |

Si ni `key` ni `id` ne sont indiqués, une erreur sera affichée.

Exemple :

```
{{:delete key="facture_43"}}
```

## admin_header

Affiche l'entête de l'administration de l'association.

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `title` | *optionnel* | Titre de la page |
| `layout` | *optionnel* | Aspect de la page. Peut être `public` pour une page publique simple (sans le menu), ou `raw` pour une page vierge (sans aucun menu ni autre élément). Défaut : vide (affichage du menu) |
| `current` | *optionnel* | Indique quel élément dans le menu de gauche doit être marqué comme sélectionné |
| `custom_css` | *optionnel* | Fichier CSS supplémentaire à appeler dans le `<head>` |

```
{{:admin_header title="Gestion des dons" current="acc"}}
```

Liste des choix possibles pour `current` :

* `home` : menu Accueil
* `users` : menu Membres
* `users/new` : sous-menu "Ajouter" de Membres
* `users/services` : sous-menu "Activités et cotisations" de Membres
* `users/mailing` : sous-menu "Message collectif" de Membres
* `acc` : menu Comptabilité
* `acc/new` : sous-menu "Saisie" de Comptabilité
* `acc/accounts` : sous-menu "Comptes"
* `acc/simple` : sous-menu "Suivi des écritures"
* `acc/years` : sous-menu "Exercices et rapports"
* `docs` : menu Documents
* `web` : menu Site web
* `config` : menu Configuration
* `me` : menu "Mes infos personnelles"
* `me/services` : sous-menu "Mes activités et cotisations"

Exemple d'utilisation de `custom_css` depuis un module :

```
{{:admin_header title="Mon module" custom_css="./style.css"}}
```

## admin_footer

Affiche le pied de page de l'administration de l'association.

```
{{:admin_footer}}
```

## input

Crée un champ de formulaire HTML. Cette fonction est une extension à la balise `<input>` en HTML, mais permet plus de choses.

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `name` | **obligatoire** | Nom du champ |
| `type` | **obligatoire** | Type de champ |
| `required` | *optionnel* | Mettre à `true` si le champ est obligatoire |
| `label` | *optionnel* | Libellé du champ |
| `help` | *optionnel* | Texte d'aide, affiché sous le champ |
| `default` | *optionnel* | Valeur du champ par défaut, si le formulaire n'a pas été envoyé, et que la valeur dans `source` est vide |
| `source` | *optionnel* | Source de pré-remplissage du champ. Si le nom du champ est `montant`, alors la valeur de `[source].montant` sera affichée si présente. |

Si `label` ou `help` sont spécifiés, le champ sera intégré à une balise HTML `<dd>`, et le libellé sera intégré à une balise `<dt>`. Dans ce cas il faut donc que le champ soit dans une liste `<dl>`. Si ces deux paramètres ne sont pas spécifiés, le champ sera le seul tag HTML.

```
<dl>
	{{:input name="amount" type="money" label="Montant" required=true}}
</dl>
```

Note : le champ aura comme `id` la valeur `f_[name]`. Ainsi un champ avec `amount` comme `name` aura `id="f_amount"`.

### Valeur du champ

La valeur du champ est remplie avec :

* la valeur dans `$_POST` qui correspond au `name` ;
* sinon la valeur dans `source` (tableau) avec le même nom (exemple : `$source[name]`) ;
* sinon la valeur de `default` est utilisée.

Note : le paramètre `value` n'est pas supporté sauf pour checkbox et radio.

### Types de champs supportés

* les types classiques de `input` en HTML : text, search, email, url, file, date, checkbox, radio, password, etc.
  * Note : pour checkbox et radio, il faut utiliser le paramètre `value` en plus pour spécifier la valeur.
* `textarea`
* `money` créera un champ qui attend une valeur de monnaie au format décimal
* `datetime` créera un champ date et un champ texte pour entrer l'heure au format `HH:MM`
* `radio-btn` créera un champ de type radio mais sous la forme d'un gros bouton
* `select` crée un sélecteur de type `<select>`. Dans ce cas il convient d'indiquer un tableau associatif dans le paramètre `options`.
* `select_groups` crée un sélecteur de type `<select>`, mais avec des `<optgroup>`. Dans ce cas il convient d'indiquer un tableau associatif à deux niveaux dans le paramètre `options`.
* `list` crée un champ permettant de sélectionner un ou des éléments (selon si le paramètre `multiple` est `true` ou `false`) dans un formulaire externe. Le paramètre `can_delete` indique si l'utilisateur peut supprimer l'élément déjà sélectionné (si `multiple=false`). La sélection se fait à partir d'un  formulaire  dont l'URL doit être spécifiée dans le paramètre `target`. Les formulaires actuellement supportés sont :
  * `!acc/charts/accounts/selector.php?targets=X` pour sélectionner un compte du plan comptable, où X est une liste de types de comptes qu'il faut permettre de choisir (séparés par des `:`)
  * `!users/selector.php` pour sélectionner un membre

## button

Affiche un bouton, similaire à `<button>` en HTML, mais permet d'ajouter une icône par exemple.

```
{{:button type="submit" name="save" label="Créer ce membre" shape="plus" class="main"}}
```

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `type` | optionnel | Type du bouton |
| `name` | optionnel | Nom du bouton |
| `label` | optionnel | Label du bouton |
| `shape` | optionnel | Affiche une icône en préfixe du label |
| `class` | optionnel | Classe CSS |
| `title` | optionnel | Attribut HTML `title` |
| `disabled` | optionnel | Désactive le bouton si `true` |


## link

Affiche un lien.

```
{{:link href="!users/new.php" label="Créer un nouveau membre"}}
```

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `href` | **obligatoire** | Adresse du lien |
| `label` | **obligatoire** | Libellé du lien |
| `target` | *optionnel* | Cible du lien, utiliser `_dialog` pour que le lien s'ouvre dans une fenêtre modale. |


## linkbutton

Affiche un lien sous forme de faux bouton, avec une icône si le paramètre `shape` est spécifié.

```
{{:linkbutton href="!users/new.php" label="Créer un nouveau membre" shape="plus"}}
```

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `href` | **obligatoire* | Adresse du lien |
| `label` | **obligatoire** | Libellé du bouton |
| `target` | *optionnel* | Cible de l'ouverture du lien |
| `shape` | *optionnel* | Affiche une icône en préfixe du label |

Si on utilise `target="_dialog"` alors le lien s'ouvrira dans une fenêtre modale (iframe) par dessus la page actuelle.

Si on utilise `target="_blank"` alors le lien s'ouvrira dans un nouvel onglet.

## icon

Affiche une icône.

```
{{:icon shape="print"}}
```

| Paramètre | Obligatoire ou optionnel ? | Fonction |
| :- | :- | :- |
| `shape` | **obligatoire** | Forme de l'icône. |


# Formes d'icônes disponibles

![](shapes.png)

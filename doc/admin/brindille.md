Title: Documentation du langage Brindille dans Paheko

{{{.nav
* **[Documentation Brindille](brindille.html)**
* [Fonctions](brindille_functions.html)
* [Sections](brindille_sections.html)
* [Filtres](brindille_modifiers.html)
}}}

<<toc aside>>

# Introduction

La syntaxe utilisée dans les squelettes du site web et des modules s'appelle **Brindille**.

Si vous avez déjà fait de la programmation, elle ressemble à un mélange de Mustache, Smarty, Twig et PHP.

Son but est de permettre une grande flexibilité, sans avoir à utiliser un "vrai" langage de programmation, mais en s'en rapprochant suffisamment quand même.

## Fichiers

Un fichier texte contenant du code Brindille est appelé un **squelette**.

Seuls les fichiers ayant une des extensions `.tpl`, `.html`, `.htm`, `.skel` ou `.xml` seront traités par Brindille.
De même, les fichiers qui n'ont pas d'extension seront également traités par Brindille.

Les autres types de fichiers seront renvoyés sans traitement, comme des fichiers "bruts". En d'autres termes, il n'est pas possible de mettre du code *Brindille* dans des fichiers qui ne sont pas des fichiers textes.

# Syntaxe de base

## Affichage de variable

Une variable est affichée à l'aide de la syntaxe : `{{$date}}` affichera la valeur brute de la date par exemple : `2020-01-31 16:32:00`.

La variable peut être modifiée à l'aide de filtres de modification, qui sont ajoutés avec le symbole de la barre verticale (pipe `|`) : `{{$date|date_long}}` affichera une date au format long : `jeudi 7 mars 2021`.

Ces filtres peuvent accepter des paramètres, séparés par deux points `:`. Exemple : `{{$date|date:"%d/%m/%Y"}}` affichera `31/01/2020`.

Par défaut la variable sera recherchée dans le contexte actuel de la section, si elle n'est pas trouvée elle sera recherchée dans le contexte parent (section parente), etc. jusqu'à trouver la variable.

Il est possible de faire référence à une variable d'un contexte particulier avec la notation à points : `{{$article.date}}`.  
La même syntaxe est utilisée pour accéder aux membres d'un tableau : `{{$labels.new_page}}`.

Il existe deux variables de contexte spécifiques : `$_POST` et `$_GET` qui permettent d'accéder aux données envoyées dans un formulaire et dans les paramètres de la page.

Par défaut le filtre `escape` est appliqué à toutes les variables pour protéger les variables contre les injections de code HTML. Ce filtre est appliqué en dernier, après les autres filtres. Il est possible de contourner cet automatisme en rajoutant le filtre `escape` ou `raw` explicitement. `raw` désactive tout échappement, alors que `escape` est utilisé pour changer l'ordre d'échappement. Exemple :

```
{{:assign text = "Coucou\nça va ?" }}
{{$text|escape|nl2br}}
```

Donnera bien `Coucou<br />ça va ?`. Si on n'avait pas indiqué le filtre `escape` le résultat serait `Coucou&lt;br /&gt;ça va ?`.

### Échappement des caractères spéciaux dans les chaînes de caractère

Pour inclure un caractère spécial (retour de ligne, guillemets ou apostrophe) dans une chaîne de caractère il suffit d'utiliser un antislash :

```
{{:assign text="Retour \n à la ligne"}}
{{:assign text="Utiliser des \"apostrophes\"}}
```

## Ordre de recherche des variables

Par défaut les variables sont recherchées dans l'ordre inverse, c'est à dire que sont d'abord recherchées les variables avec le nom demandé dans la section courante. Si la variable n'existe pas dans la section courante, alors elle est recherchée dans la section parente, et ainsi de suite jusqu'à ce que la variable soit trouvée, où qu'il n'y ait plus de section parente.

Prenons cet exemple :

```
{{#articles uri="Actualite"}}
  <h1>{{$title}}</h1>
    {{#images parent=$path limit=1}}
      <img src="{{$thumb_url}}" alt="{{$title}}" />
    {{/images}}
{{/articles}}
```

Dans la section `articles`, `$title` est une variable de l'article, donc la variable est celle de l'article.

Dans la section `images`, les images n'ayant pas de titre, la variable sera celle de l'article de la section parente, alors que `$thumb_url` sera lié à l'image.

## Conflit de noms de variables

Imaginons que nous voulions mettre un lien vers l'article sur l'image de l'exemple précédent :

```
{{#articles uri="Actualite"}}
  <h1>{{$title}}</h1>
    {{#images parent=$path limit=1}}
      
    {{/images}}
{{/articles}}
```

Problème, ici `$url` fera référence à l'URL de l'image elle-même, et non pas l'URL de l'article.

La solution est d'ajouter un point au début du nom de variable : `{{$.url}}`.

Un point au début d'un nom de variable signifie que la variable est recherchée à partir de la section précédente. Il est possible d'utiliser plusieurs points, chaque point correspond à un niveau à remonter. Ainsi `$.url` cherchera la variable dans la section parente (et ses sections parentes si elle n'existe pas, etc.). De même, `$..url` cherchera dans la section parente de la section parente.

## Création manuelle de variable

### Variable simple

La création d'une variable se fait via l'appel de la fonction `{{:assign}}`.

Exemple :

```
{{:assign source='wiki'}}
{{* est identique à : *}}
{{:assign var='source' value='wiki'}}
```

Un deuxième appel à `{{:assign}}` avec le même nom de variable écrase la valeur précédente

```
{{:assign var='source' value='wiki'}}
{{:assign var='source' value='documentation'}}

{{$source}}
{{* => Affiche documentation *}}
```

### Nom de variable dynamique

Il est possible de créer une variable dont une partie du nom est dynamique.

```
{{:assign type='user'}}
{{:assign var='allowed_%s'|args:$type value='jeanne'}}
{{:assign type='side'}}
{{:assign var='allowed_%s'|args:$type value='admin'}}

{{$allowed_user}} => jeanne
{{$allowed_side}} => admin
```

[Documentation complète de la fonction {{:assign}}](brindille_functions.html#assign).

### Tableaux *(array)*

Pour créer des tableaux, il suffit d'utiliser des points `.` dans le nom de la variable (ex : `colors.yellow`). Il n'y a pas besoin d'initialiser le tableau avant de le remplir.

```
{{:assign var='colors.admin' value='blue'}}
{{:assign var='colors.website' value='grey'}}
{{:assign var='colors.debug' value='yellow'}}
```

On accède ensuite à la valeur d'un élément du tableau avec la même syntaxe : `{{$colors.website}}`

Méthode rapide de création du même tableau :

```
{{:assign var='colors' admin='blue' website='grey' debug='yellow'}}
```

Pour ajouter un élément à la suite du tableau sans spécifier de clef *(push)*, il suffit de terminer le nom de la variable par un point `.` sans suffixe.

Exemple :

```
{{* Ajouter les valeurs 17, 43 et 214 dans $processed_ids *}}

{{:assign var='processed_ids.' value=17}}
{{:assign var='processed_ids.' value=43}}
{{:assign var='processed_ids.' value=214}}
```

#### Clef dynamique de tableau

Il est possible d'accéder dynamiquement à un des éléments d'un tableau de la manière suivante :

```
{{:assign location='admin'}}
{{:assign var='location_color' from='colors.%s'|args:$location}}

{{$location_color}} => blue
```

Exemple plus complexe :

```
{{:assign var='type_whitelist.text' value=1}}
{{:assign var='type_whitelist.html' value=1}}

{{#foreach from=$documents item='document'}}
  {{:assign var='allowed' value='type_whitelist.%s'|args:$document->type}}
  {{if $allowed !== null}}
    {{:include file='document/'|cat:$type:'.tpl' keep='document'}}
  {{/if}}
{{/foreach}}
```

Il est également possible de créer un membre dynamique d'un tableau en conjuguant les syntaxes précédentes.

Exemple :

```
{{:assign var='type_whitelist.%s'|args:$type value=1}}
```

## Conditions

Il est possible d'utiliser des conditions de type **"si"** (`if`), **"sinon si"** (`elseif`) et **"sinon"** (`else`). Celles-ci sont terminées par un block **"fin si"** (`/if`).

```
{{if $date|date:"%Y" > 2020}}
    La date est en 2020
{{elseif $article.status == 'draft'}}
    La page est un brouillon
{{else}}
    Autre chose.
{{/if}}
```

### Test d'existence

Brindille ne fait pas de différences entre une variable qui n'existe pas, et une variable définie à `null`.
On peut donc tester l'existence d'une variable en la comparant à `null` comme ceci :

```
{{if $session !== null}}
  Session en cours pour l'utilisateur/trice {{$session->user->name}}.
{{else}}
  Session inexistante.
{{/if}}
```


## Fonctions

### Fonctions natives

Une fonction va répondre à certains paramètres et renvoyer un résultat ou réaliser une action.

**Un bloc de fonction commence par le signe deux points `:`.**

```
{{:http code=404}}
```

Contrairement aux autres types de blocs, et comme pour les variables, il n'y a pas de bloc fermant (avec un slash `/`).

## Sections

Une section est une partie de la page qui sera répétée une fois, plusieurs fois, ou zéro fois, selon ses paramètres et le résultat (c'est une "boucle"). Une section commence par un bloc avec un signe hash (`#`) et se termine par un bloc avec un slash (`/`).

Un exemple simple avec une section qui n'aura qu'une seule répétition :

```
{{#categories uri=$_GET.uri}}
    <h1>{{$title}}</h1>
{{/categories}}
```

Il est possible d'utiliser une condition `{{else}}` avant la fin du bloc pour avoir du contenu alternatif si la section ne se répète pas (dans ce cas si aucune catégorie ne correspond au critère).

Un exemple de sous-section

```
{{#categories uri=$_GET.uri}}
    <h1>{{$title}}</h1>

    {{#articles parent=$path order="published DESC" limit="10"}}
        <h2></h2>
        <p>{{$content|truncate:600:"..."}}</p>
    {{else}}
        <p>Aucun article trouvé.</p>
    {{/articles}}

{{/categories}}
```

Voir la référence des sections pour voir quelles sont les sections possibles et quel est leur comportement.

## Bloc litéral

Pour qu'une partie du code ne soit pas interprété, pour éviter les conflits avec certaines syntaxes, il est possible d'utiliser un bloc `literal` :

```
{{literal}}
<script>
// Ceci ne sera pas interprété
function test (a) {{
}}
</script>
{{/literal}}
```


## Commentaires

Les commentaires sont figurés dans des blocs qui commencent et se terminent par une étoile (`*`) :

```
{{* Ceci est un commentaire
Il sera supprimé du résultat final
Il peut contenir du code qui ne sera pas interprété :
{{if $test}}
OK
{{/if}}
*}}
```


# Liste des variables définies par défaut

Ces variables sont définies tout le temps :

| Nom de la variable | Valeur |
| :- | :- |
| `$_GET` | Alias de la super-globale _GET de PHP. |
| `$_POST` | Alias de la super-globale _POST de PHP. |
| `$root_url` | Adresse racine du site web Paheko. |
| `$request_url` | Adresse de la page courante. |
| `$admin_url` | Adresse de la racine de l'administration Paheko. |
| `$visitor_lang` | Langue préférée du visiteur, sur 2 lettres (exemple : `fr`, `en`, etc.). |
| `$logged_user` | Informations sur le membre actuellement connecté dans l'administration (vide si non connecté). |
| `$dialog` | Vaut `TRUE` si la page est dans un dialogue (iframe sous forme de pop-in dans l'administration). |
| `$now` | Contient la date et heure courante. |
| `$legal_line` | Contient la ligne de bas de page des mentions légales (sous forme de code HTML) qui doit être présente en bas des pages publiques. |
| `$config.org_name` | Nom de l'association |
| `$config.org_email` | Adresse e-mail de l'association |
| `$config.org_phone` | Numéro de téléphone de l'association |
| `$config.org_address` | Adresse postale de l'association |
| `$config.org_web` | Adresse du site web de l'association |
| `$config.files.logo` | Adresse du logo de l'association, si définit dans la personnalisation |
| `$config.files.favicon` | Adresse de l'icône de favoris de l'association, si défini dans la personnalisation |
| `$config.files.signature` | Adresse de l'image de signature, si défini dans la personnalisation |

À celles-ci s'ajoutent [les variables spéciales des modules](modules.html#variables_speciales) lorsque le script est chargé dans un module.

# Erreurs

Si une erreur survient dans un squelette, que ça soit au niveau d'une erreur de syntaxe, ou une erreur dans une fonction, filtre ou section, alors elle sera affichée selon les règles suivantes :

* si le membre connecté est administrateur, une erreur est affichée avec le code du squelette ;
* sinon l'erreur est affichée sans le code.


# Avertissement sur la sécurité des requêtes SQL

Attention, en utilisant la section `{{#select ...}}`, ou une des sections SQL (voir plus bas), avec des paramètres qui ne seraient pas protégés, il est possible qu'une personne mal intentionnée ait accès à des parties de la base de données à laquelle vous ne désirez pas donner accès.

Pour protéger contre cela il est essentiel d'utiliser les paramètres nommés.

Exemple de requête dangereuse :

```
{{#sql select="*" tables="users" where="id = %s"|args:$_GET.id}}
...
{{/sql}}
```

On se dit que la requête finale sera donc : `SELECT * FROM users WHERE id = 42;` si le numéro 42 est passé dans le paramètre `id` de la page.

Imaginons qu'une personne mal-intentionnée indique dans le paramètre `id` de la page la chaîne de caractère suivante : `0 OR 1`. Dans ce cas la requête exécutée sera  `SELECT * FROM users WHERE id = 0 OR 1;`. Cela aura pour effet de lister tous les membres, au lieu d'un seul.

Pour protéger contre cela il convient d'utiliser un paramètre nommé :

```
{{#sql select="*" tables="users" where="id = :id" :id=$_GET.id}}
```

Dans ce cas la requête malveillante générée sera `SELECT * FROM users WHERE id = '0 OR 1';`. Ce qui aura pour effet de ne lister aucun membre.

## Mesures prises pour la sécurité des données

Dans Brindille, il n'est pas possible de modifier ou supprimer des éléments dans la base de données avec les requêtes SQL directement. Seules les requêtes SQL en lecture (`SELECT`) sont permises.

Cependant certaines fonctions permettent de modifier ou créer des éléments précis (écritures par exemple), ce qui peut avoir un effet de remplir ou modifier des données par une personne mal-intentionnée, donc attention à leur utilisation.

Les autres mesures prises sont :

* impossibilité d'accéder à certaines données sensibles (mot de passe, logs de connexion, etc.)
* incitation forte à utiliser les paramètres nommés dans la documentation
* protection automatique des variables dans la section `{{#select}}`
* fourniture de fonctions pour protéger les chaînes de caractères contre l'injection SQL
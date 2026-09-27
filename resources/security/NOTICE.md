# common-passwords.txt : origine et licence

Données dérivées du dépôt SecLists (https://github.com/danielmiessler/SecLists), dossier
`Passwords/Common-Credentials` : `100k-most-used-passwords-NCSC.txt` (NCSC britannique, issu de
Have I Been Pwned) et `xato-net-10-million-passwords-100000.txt`.

Transformation : union, minuscules, NFC, dédoublonnage, tri par octets (`LC_ALL=C sort -u`), puis
conservation des seules entrées atteignables par `PasswordPolicy` (12 caractères ou plus, ou 4 ou plus
bordées de lettres pour la détection d'un mot courant décoré). Le test
`PasswordPolicyTest::testBlocklistLookupIsExact` vérifie le tri et cette règle.

Licence de SecLists : MIT.

    Copyright (c) 2018 Daniel Miessler

    Permission is hereby granted, free of charge, to any person obtaining a copy of this software and
    associated documentation files (the "Software"), to deal in the Software without restriction,
    including without limitation the rights to use, copy, modify, merge, publish, distribute,
    sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is
    furnished to do so, subject to the following conditions:

    The above copyright notice and this permission notice shall be included in all copies or
    substantial portions of the Software.

    THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT
    NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
    NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES
    OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
    CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

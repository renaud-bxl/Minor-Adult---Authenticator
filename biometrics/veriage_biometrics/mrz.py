"""Zone de lecture automatique (MRZ, ICAO 9303) : formats TD1, TD2 et TD3, chiffres de contrôle 7-3-1,
âge exact et expiration.

Minimisation : l'analyse ne conserve que ce qui sert à la décision (format, dates, résultat des
contrôles). Le nom, le numéro du document et les données facultatives (le numéro de registre national
belge figure dans la zone facultative de la carte d'identité) sont lus pour les chiffres de contrôle,
puis oubliés : ils ne sortent jamais de ce module.

Formats :
- TD1 : 3 lignes de 30 caractères (cartes d'identité UE, dont la carte belge, MRZ au verso) ;
- TD2 : 2 lignes de 36 caractères ;
- TD3 : 2 lignes de 44 caractères (passeports, MRZ sous la photo).
"""
from __future__ import annotations

import calendar
import datetime as dt
import itertools
from dataclasses import dataclass, field

FILLER = "<"
ALPHABET = frozenset("ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789<")
WEIGHTS = (7, 3, 1)
LENGTHS = {"TD1": (30, 3), "TD2": (36, 2), "TD3": (44, 2)}

# Types de document acceptés (première lettre du code) : identité (I, A, C) et passeport (P).
# Les visas (V) ne sont pas des pièces d'identité.
ACCEPTED_CODES = {"TD1": "IAC", "TD2": "IAC", "TD3": "P"}

# Confusions typiques de l'OCR, corrigées selon la nature attendue du caractère.
TO_DIGIT = str.maketrans({"O": "0", "Q": "0", "D": "0", "U": "0", "I": "1", "L": "1", "T": "1",
                          "Z": "2", "S": "5", "G": "6", "B": "8"})
TO_ALPHA = str.maketrans({"0": "O", "1": "I", "2": "Z", "5": "S", "6": "G", "8": "B"})
# Ambiguïtés essayées dans les champs alphanumériques (numéro, données facultatives), guidées par le
# chiffre de contrôle.
AMBIGUOUS = {"0": "O", "O": "0", "1": "I", "I": "1", "8": "B", "B": "8", "5": "S", "S": "5", "2": "Z", "Z": "2"}
MAX_AMBIGUOUS = 8


class MrzError(ValueError):
    """MRZ illisible ou invalide ; « reason » est un code stable (jamais la valeur lue)."""

    def __init__(self, reason: str) -> None:
        super().__init__(reason)
        self.reason = reason


def char_value(char: str) -> int:
    if char.isdigit():
        return int(char)
    if "A" <= char <= "Z":
        return ord(char) - ord("A") + 10
    if char == FILLER:
        return 0
    raise MrzError("mrz_invalid_character")


def check_digit(field: str) -> str:
    """Chiffre de contrôle ICAO 9303 : somme pondérée 7-3-1, modulo 10."""
    return str(sum(char_value(c) * WEIGHTS[i % 3] for i, c in enumerate(field)) % 10)


def check_ok(field: str, digit: str) -> bool:
    # Champ entièrement vide (« < ») : le chiffre de contrôle peut lui-même valoir « < » (TD3, numéro personnel).
    if digit == FILLER:
        return set(field) <= {FILLER}
    return digit.isdigit() and check_digit(field) == digit


@dataclass(frozen=True)
class MrzData:
    """Ce que l'on garde d'une MRZ : format, code du document, dates, contrôles, et le numéro du document,
    uniquement pour lier les deux faces de la pièce (document.check_sides). Ni nom, ni zone facultative.
    Rien de ceci ne sort du service (le numéro est exclu de repr, donc de toute trace)."""

    format: str
    document_code: str
    birth_date: dt.date
    expiry_date: dt.date
    checks: tuple[tuple[str, bool], ...]
    document_number: str = field(default="", repr=False)

    @property
    def valid(self) -> bool:
        return all(ok for _, ok in self.checks)

    def age_on(self, today: dt.date) -> int:
        return age_on(self.birth_date, today)

    def expired_on(self, today: dt.date) -> bool:
        # Le document est valable jusqu'à sa date d'expiration incluse.
        return self.expiry_date < today


def age_on(birth: dt.date, today: dt.date) -> int:
    """Âge exact en années révolues. L'anniversaire compte le jour même ; une personne née un 29 février
    prend un an le 1er mars les années non bissextiles (règle prudente : jamais d'avance sur l'âge)."""
    if birth > today:
        raise MrzError("mrz_birth_date_in_future")
    had_birthday = (today.month, today.day) >= (birth.month, birth.day)
    return today.year - birth.year - (0 if had_birthday else 1)


def _date_parts(field: str) -> tuple[int, int | None, int | None]:
    """AAMMJJ ; mois ou jour inconnus (« << » ou « XX », prévus par l'ICAO) → None."""
    if len(field) != 6 or not field[:2].isdigit():
        raise MrzError("mrz_invalid_date")
    year = int(field[:2])
    parts: list[int | None] = []
    for chunk in (field[2:4], field[4:6]):
        if chunk in ("<<", "XX"):
            parts.append(None)
        elif chunk.isdigit():
            parts.append(int(chunk))
        else:
            raise MrzError("mrz_invalid_date")
    return year, parts[0], parts[1]


def _build_date(year: int, month: int | None, day: int | None) -> dt.date:
    # Parties inconnues : on retient la date la plus TARDIVE possible (âge le plus bas : prudent).
    month = 12 if month is None else month
    if not 1 <= month <= 12:
        raise MrzError("mrz_invalid_date")
    last = calendar.monthrange(year, month)[1]
    day = last if day is None else day
    if not 1 <= day <= last:
        raise MrzError("mrz_invalid_date")
    return dt.date(year, month, day)


def birth_date(field: str, today: dt.date) -> dt.date:
    """Siècle : la date la plus récente qui ne soit pas dans le futur (une personne de plus de 100 ans
    serait lue comme un enfant : l'erreur va dans le sens prudent)."""
    yy, month, day = _date_parts(field)
    for century in (2000, 1900):
        try:
            date = _build_date(century + yy, month, day)
        except MrzError:
            if century == 1900:
                raise
            continue
        if date <= today:
            return date
    raise MrzError("mrz_birth_date_in_future")


def expiry_date(field: str, today: dt.date) -> dt.date:
    yy, month, day = _date_parts(field)
    if month is None or day is None:
        raise MrzError("mrz_invalid_date")
    year = 2000 + yy
    if year > today.year + 50:
        year -= 100
    return _build_date(year, month, day)


def detect_format(lines: list[str]) -> str:
    lengths = [len(line) for line in lines]
    for name, (width, count) in LENGTHS.items():
        if len(lines) == count and all(n == width for n in lengths):
            return name
    raise MrzError("mrz_unknown_format")


# Nature attendue de chaque position : N (chiffre), A (lettre), X (alphanumérique), S (sexe), F (libre).
def _layout(fmt: str) -> list[str]:
    if fmt == "TD1":
        return ["AA" + "AAA" + "X" * 9 + "F" + "X" * 15,
                "N" * 7 + "S" + "N" * 7 + "AAA" + "X" * 11 + "N",
                "A" * 30]
    width = LENGTHS[fmt][0]
    optional = 7 if fmt == "TD2" else 14
    line2 = "X" * 9 + "N" + "AAA" + "N" * 7 + "S" + "N" * 7 + "X" * optional + ("N" if fmt == "TD2" else "FN")
    return ["AA" + "AAA" + "A" * (width - 5), line2]


def normalize(lines: list[str], fmt: str) -> list[str]:
    """Corrige les confusions d'OCR selon la nature de chaque position (O/0 dans une date, 0/O dans un
    code pays…). Les champs alphanumériques sont laissés tels quels (voir _disambiguate)."""
    out = []
    for line, kinds in zip(lines, _layout(fmt)):
        chars = []
        for char, kind in zip(line, kinds):
            if kind == "N" and char not in (FILLER, "X"):
                char = char.translate(TO_DIGIT)
            elif kind == "A" and char != FILLER:
                char = char.translate(TO_ALPHA)
            elif kind == "S":
                char = {"H": "M", "N": "M", "E": "F", "P": "F"}.get(char, char)
            chars.append(char)
        out.append("".join(chars))
    return out


def _disambiguate(field: str, digit: str) -> str:
    """Champ alphanumérique au contrôle faux : essaie les lectures ambiguës (0/O, 1/I, 8/B…) jusqu'à
    satisfaire le chiffre de contrôle. Bornée (MAX_AMBIGUOUS positions)."""
    if check_ok(field, digit):
        return field
    positions = [i for i, c in enumerate(field) if c in AMBIGUOUS][:MAX_AMBIGUOUS]
    for count in range(1, len(positions) + 1):
        for subset in itertools.combinations(positions, count):
            candidate = list(field)
            for i in subset:
                candidate[i] = AMBIGUOUS[candidate[i]]
            text = "".join(candidate)
            if check_ok(text, digit):
                return text
    return field


def _td1_number(line1: str) -> tuple[str, str]:
    """Numéro de document TD1 et son chiffre de contrôle ; au-delà de 9 caractères, la suite déborde
    dans la zone facultative (position 15 = « < », puis suite du numéro et chiffre de contrôle)."""
    number, digit, optional = line1[5:14], line1[14], line1[15:30]
    if digit == FILLER and optional[:1] != FILLER:
        overflow = optional.split(FILLER, 1)[0]
        if len(overflow) >= 2:
            return number + overflow[:-1], overflow[-1]
    return number, digit


def parse(raw_lines: list[str], today: dt.date) -> MrzData:
    """Analyse et contrôle une MRZ. Lève MrzError (code stable) si elle est inexploitable. Une MRZ dont
    un chiffre de contrôle est faux est renvoyée avec « valid » à False (décision à l'appelant)."""
    lines = [line.strip().upper().replace(" ", "") for line in raw_lines]
    if any(set(line) - ALPHABET for line in lines):
        raise MrzError("mrz_invalid_character")
    fmt = detect_format(lines)
    # Ancienne carte d'identité française (2 × 36, format national non ICAO : pas de date d'expiration dans
    # la MRZ) : non prise en charge, motif dédié plutôt que « illisible » (l'utilisateur ne réussirait jamais).
    if fmt == "TD2" and lines[0].startswith("IDFRA"):
        raise MrzError("document_unsupported")
    lines = normalize(lines, fmt)
    code = lines[0][:2]
    if code[0] not in ACCEPTED_CODES[fmt]:
        raise MrzError("mrz_unsupported_document")

    checks: list[tuple[str, bool]] = []
    if fmt == "TD1":
        l1, l2 = lines[0], lines[1]
        raw_number, number_digit = _td1_number(l1)
        number = _disambiguate(raw_number, number_digit)
        # Numéro corrigé réinjecté à sa place (débordement éventuel compris) pour le contrôle composite.
        l1 = l1[:5] + (number[:9] + FILLER + number[9:] if len(number) > 9 else number) + l1[5 + len(number) + (1 if len(number) > 9 else 0):]
        birth, birth_digit = l2[0:6], l2[6]
        expiry, expiry_digit = l2[8:14], l2[14]
        composite = l1[5:30] + l2[0:7] + l2[8:15] + l2[18:29]
        composite_digit = l2[29]
    else:
        l2 = lines[1]
        number, number_digit = _disambiguate(l2[0:9], l2[9]), l2[9]
        birth, birth_digit = l2[13:19], l2[19]
        expiry, expiry_digit = l2[21:27], l2[27]
        if fmt == "TD2":
            optional = l2[28:35]
            composite = number + number_digit + l2[13:20] + l2[21:35]
            composite_digit = l2[35]
        else:
            optional = _disambiguate(l2[28:42], l2[42])
            checks.append(("personal_number", check_ok(optional, l2[42])))
            composite = number + number_digit + l2[13:20] + l2[21:28] + optional + l2[42]
            composite_digit = l2[43]

    checks = [
        ("document_number", check_ok(number, number_digit)),
        ("birth_date", check_ok(birth, birth_digit)),
        ("expiry_date", check_ok(expiry, expiry_digit)),
        *checks,
        ("composite", check_ok(composite, composite_digit)),
    ]
    return MrzData(
        format=fmt,
        document_code=code,
        birth_date=birth_date(birth, today),
        expiry_date=expiry_date(expiry, today),
        checks=tuple(checks),
        document_number=number,
    )


def candidate_lines(text: str) -> list[list[str]]:
    """Découpe une sortie d'OCR en lignes candidates (formats plausibles), la plus probable d'abord.
    Les lignes trop courtes sont complétées par « < » (les charges de fin de ligne sont souvent perdues)."""
    rows = [line.strip().replace(" ", "").upper() for line in text.splitlines()]
    rows = ["".join(c for c in row if c in ALPHABET) for row in rows]
    rows = [row for row in rows if len(row) >= 20]
    results: list[list[str]] = []
    for fmt in ("TD1", "TD3", "TD2"):
        width, count = LENGTHS[fmt]
        for start in range(0, max(0, len(rows) - count + 1)):
            group = rows[start:start + count]
            if all(width - 4 <= len(row) <= width + 2 for row in group):
                results.append([(row + FILLER * width)[:width] for row in group])
    return results

from __future__ import annotations

_SPACE = ' \t\n\f\r'

_LOWER = str.maketrans('ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')


def ascii_lower(text: str) -> str:
    """Lowercases A-Z only, as PHP's `strtolower` does."""
    return text.translate(_LOWER)


class Element:
    """
    An element of the tree: a lowercase name, its attributes (lowercase names, decoded values,
    the first of a repeated name kept) and its children, text as strings.
    """

    def __init__(self, name: str, attributes: dict[str, str] | None = None) -> None:
        self.name = name
        self.attributes = attributes or {}
        self.children: list[Element | str] = []

    def attribute(self, name: str) -> str | None:
        return self.attributes.get(name)

    def token(self, name: str) -> str | None:
        """The attribute's value trimmed of ASCII whitespace and lowercased (ASCII), or None."""
        value = self.attributes.get(name)

        return None if value is None else ascii_lower(value.strip(_SPACE))

    def tokens(self, name: str) -> list[str]:
        """The attribute's value split at runs of ASCII whitespace, case kept."""
        value = self.attributes.get(name, '')
        for char in '\t\n\f\r':
            value = value.replace(char, ' ')

        return [token for token in value.split(' ') if token != '']

    def append(self, child: Element | str) -> None:
        if isinstance(child, str) and self.children and isinstance(self.children[-1], str):
            self.children[-1] += child
            return
        self.children.append(child)

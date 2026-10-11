"""
Sends one System One request through the official `typesafe-sdk`.

A caller passes either a `TypeSafeClient`, or a hook: a callable handed the request, a
`JevRequest`, that sends it itself and returns the SDK's `SystemOneResponse`, or None for no
answer. The hook is where a caller puts its own caching, feature switches or logging:

    def hook(request: JevRequest) -> SystemOneResponse | None:
        return client.system_one(request['state'], request['questions'])

Any exception, and anything the hook returns that is not a `SystemOneResponse`, is an
unanswered call, so chunking falls back to headings and sizes and ranking returns None.
"""
from __future__ import annotations

from collections.abc import Callable
from typing import Any, TypedDict

from typesafe_sdk import Choice, Noul, SystemOneResponse, TypeSafeClient


class JevRequest(TypedDict):
    state: dict[str, Any]
    questions: dict[str, Noul | Choice]


JevHook = Callable[[JevRequest], SystemOneResponse | None]

Jev = TypeSafeClient | JevHook


def send(jev: Jev, request: JevRequest) -> SystemOneResponse | None:
    """
    The response, or None. A choice with no labels counts as unanswered without a call, as the
    PHP SDK refuses to send one.
    """
    if any(isinstance(question, Choice) and not question.criteria for question in request['questions'].values()):
        return None

    try:
        response = (
            jev.system_one(request['state'], request['questions'])
            if isinstance(jev, TypeSafeClient)
            else jev(request)
        )
    except Exception:
        return None

    return response if isinstance(response, SystemOneResponse) else None


def noul(response: SystemOneResponse, name: str) -> float | None:
    """The probability of yes for question `name`, or None when no noul answer came back for it."""
    answer = response.nouls.get(name)

    return None if answer is None else float(answer.noul)


def choice(response: SystemOneResponse, name: str) -> str | None:
    answer = response.choices.get(name)

    return None if answer is None else answer.choice

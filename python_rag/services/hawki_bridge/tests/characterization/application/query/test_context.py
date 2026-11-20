"""Generated-answer context and citation behavior."""

import unittest

from hawki_bridge.application.query.context import (
    build_grounded_answer_prompt,
    normalize_generated_answer,
)


class GeneratedAnswerContextTests(unittest.TestCase):
    def test_citation_only_answer_becomes_actionable_message(self) -> None:
        self.assertEqual(
            normalize_generated_answer("[Source 1]."),
            "The model did not produce a substantive draft answer. "
            "Review the retrieved sources below.",
        )

    def test_substantive_answer_keeps_source_citations(self) -> None:
        answer = "The fee is listed in the regulation [Source 1]."

        self.assertEqual(normalize_generated_answer(answer), answer)

    def test_grounded_prompt_rejects_standalone_citations(self) -> None:
        system_prompt, _user_prompt = build_grounded_answer_prompt(
            "What fee applies?",
            [
                {
                    "idx": 1,
                    "title": "Fee regulation",
                    "url": "",
                    "snippet": "A fee applies.",
                    "component_type": "chunk",
                    "source_format": None,
                }
            ],
            [],
        )

        self.assertIn("never return a citation by itself", system_prompt)

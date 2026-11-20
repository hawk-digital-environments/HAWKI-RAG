"""Characterization of the unified query and document term-extraction seam."""

from __future__ import annotations

import unittest


class QueryTermExtractionTests(unittest.TestCase):
    """Protect query term policy: folding, ordinals, scope stripping."""

    def test_query_terms_keep_folded_pairs_for_lexical_matching(self) -> None:
        from hawki_bridge.application.query.lexical import fold_text, query_terms

        terms = query_terms("Bauklötze")

        self.assertEqual(terms.terms, ("bauklötze",))
        self.assertEqual(terms.folded, ("bauklötze", "bauklotze"))
        self.assertEqual(
            fold_text("Bauklötze für große Kinder"), "bauklotze fur grosse kinder"
        )

    def test_query_terms_keep_ordinals_and_remove_dataset_instructions(self) -> None:
        from hawki_bridge.application.query.lexical import query_terms

        terms = query_terms("Was ist die dritte Mahnung in mein Dataset?")

        self.assertIn("dritte", terms.terms)
        self.assertIn("mahnung", terms.terms)
        self.assertNotIn("dataset", terms.terms)
        self.assertNotIn("mein", terms.terms)

    def test_query_terms_fallback_keeps_short_words(self) -> None:
        from hawki_bridge.application.query.lexical import query_terms

        terms = query_terms("Was ist das?")

        self.assertEqual(terms.terms, ("was", "ist", "das"))
        self.assertEqual(terms.folded, ("was", "ist", "das"))

    def test_query_terms_behave_like_an_ordered_term_collection(self) -> None:
        from hawki_bridge.application.query.lexical import query_terms

        terms = query_terms("Bauklötze Holzspielzeug")

        self.assertEqual(len(terms), 2)
        self.assertTrue("holzspielzeug" in terms)
        self.assertEqual(list(terms), ["bauklötze", "holzspielzeug"])


class DocumentTermExtractionTests(unittest.TestCase):
    """Protect document term policy: plain extraction without query heuristics."""

    def test_document_terms_filter_stopwords_without_folding(self) -> None:
        from hawki_bridge.application.query.lexical import document_terms

        terms = document_terms("Termine für neue Mitarbeiter")

        self.assertEqual(terms.terms, ("termine", "mitarbeiter"))

    def test_document_terms_do_not_strip_dataset_instructions(self) -> None:
        from hawki_bridge.application.query.lexical import document_terms

        terms = document_terms("Weiterführende Einträge in unserem Datensatz")

        self.assertIn("datensatz", terms.terms)
        self.assertIn("weiterführende", terms.terms)


if __name__ == "__main__":
    unittest.main()

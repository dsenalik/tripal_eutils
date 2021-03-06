<?php

/**
 * Parses BioSample XML.
 *
 * @ingroup parsers
 */
class EUtilsBioSampleParser implements EUtilsParserInterface {

  /**
   * Parse the XML into an array.
   *
   * @param \SimpleXMLElement $xml
   *
   * @return array
   *   An array of parsed data
   *
   * @throws \Exception
   */
  public function parse(SimpleXMLElement $xml) {
    if (!isset($xml->BioSample)) {
      throw  new Exception('Invalid BioSample XML provided. Possibly because the given accession cannot be found.');
    }

    // Primary data.
    $primary_accession = (string) $xml->BioSample['accession'];
    $description = (string) $xml->BioSample->Description->Comment;

    if (!empty($xml->BioSample->Description->Comment->Paragraph)) {
      $description = $this->extractParagraphs($xml->BioSample->Description->Comment);
    }

    // Get accessions.
    $accessions = $this->extractAttributes($xml->BioSample->Ids);

    // Get properties.
    $attributes = $this->extractAttributes($xml->BioSample->Attributes);
    // Check and extract owner given db_label.
    $this->attachDbLabel($attributes, $accessions);
    // Get projects from Links if present.
    $projects = $xml->BioSample->Links ? $this->extractProjects($xml->BioSample->Links) : [];

    return [
      'name' => $primary_accession,
      'accessions' => $accessions,
      'description' => $description,
      'attributes' => $attributes,
      'contact' => $this->extractContact($xml->BioSample),
      'organism' => $this->extractOrganism($xml->BioSample),
      'full_ncbi_xml' => $xml->BioSample->asXML(),
      'projects' => $projects,
    ];
  }

  /**
   * Extract the db_label from accessions.
   *
   * @param array $attributes
   * @param array $accessions
   */
  protected function attachDbLabel(array &$attributes, array $accessions) {
    $accessions = array_filter(
      $accessions, function ($accession) {
        return isset($accession['db_label'])
        && strtolower($accession['db_label']) === 'sample name';
      }
    );

    foreach ($accessions as $accession) {
      $attributes[] = [
        'value' => $accession['value'],
        'harmonized_name' => 'submitter_provided_accession',
        'attribute_name' => 'submitter_provided_accession',
        'display_name' => $accession['db_label'],
      ];
    }
  }

  /**
   * Get the organism.
   *
   * @param \SimpleXMLElement $xml
   *
   * @return array
   *   The organism data [ taxonomy_name, taxonomy_id, value ]
   */
  protected function extractOrganism(\SimpleXMLElement $xml) {
    if (!isset($xml->Description->Organism)) {
      return NULL;
    }

    $organism = $xml->Description->Organism;

    return current($organism->attributes());
  }

  /**
   * Get the owner name.
   *
   * @param \SimpleXMLElement $xml
   *
   * @return string|NUll
   */
  protected function extractContact(\SimpleXMLElement $xml) {
    return (string) $xml->Owner->Name ?? NULL;
  }

  /**
   * Extract paragraphs.
   *
   * @param \SimpleXMLElement $comment
   *
   * @return array
   *   Array of strings.
   */
  protected function extractParagraphs(\SimpleXMLElement $comment) {
    $paragraphs = [];

    foreach ($comment->children() as $paragraph) {
      $paragraphs[] = (string) $paragraph;
    }

    return implode('  ', $paragraphs);
  }

  /**
   * Extract attributes.
   *
   * @param \SimpleXMLElement $elements
   *
   * @return array
   *   Attributes returned as key => value.
   */
  protected function extractAttributes(SimpleXMLElement $elements) {
    $data = [];
    foreach ($elements->children() as $element) {
      $datum = [
        'value' => (string) $element,
      ];

      foreach ($element->attributes() as $key => $value) {
        $datum[$key] = (string) $value;
      }

      $data[] = $datum;
    }

    return $data;
  }

  /**
   * Fetches bioprojects in the Links tag.
   *
   * @param \SimpleXMLElement $links
   *   Links of form <Links><Link>uid</Link></Links>.
   *
   * @return array
   *   array of bioproject uids.
   */
  protected function extractProjects(SimpleXMLElement $links) {
    $projects = [];

    foreach ($links->children() as $link) {

      $type = $link->attributes()->target;
      if ($type == 'bioproject') {
        $projects[] = (string) $link;
      }
    }

    return $projects;

  }

}

<?php

namespace Drupal\commerce_auspost\PostageAssessment;

/**
 * Defines an AusPost PAC API response.
 *
 * @package Drupal\commerce_auspost\PostageAssessment
 */
class Response implements ResponseInterface {

  /**
   * PAC API request.
   *
   * @var \Drupal\commerce_auspost\PostageAssessment\RequestInterface
   */
  private $request;

  /**
   * API response from the AusPost library.
   *
   * @var array
   */
  private $response;

  /**
   * {@inheritdoc}
   */
  public function setRequest(RequestInterface $request) {
    $this->request = $request;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequest() {
    return $this->request;
  }

  /**
   * {@inheritdoc}
   */
  public function setResponse($response) {
    $this->response = $response;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse() {
    return $this->response;
  }

  /**
   * {@inheritdoc}
   */
  public function getPostage() {
    if ($this->response === NULL) {
      throw new ResponseException('No API response is set.');
    }

    return (float) $this->response->getTotalCost();
  }

}

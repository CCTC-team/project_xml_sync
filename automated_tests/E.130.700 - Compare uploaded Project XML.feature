Feature: E.130.700 - The system shall support the ability to compare a project with an uploaded Project XML and list every design difference without changing the project.

  As a REDCap end user
  I want to see every difference between the TEST copy of a trial and the production project
  So that no amendment is missed when the changes are copied across

  Scenario: Enable external module from Control Center
    Given I login to REDCap with the user "Test_Admin"
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    Then I should see "External Modules - Module Manager"
    When I click on the button labeled "Enable a module"
    And I wait for 2 seconds
    Then I should see "Available Modules"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync"
    And I wait for 1 second
    And I click on the button labeled "Enable"
    Then I should see "Project XML Sync - v1.0.0"

  Scenario: E.130.700 - Upload the amended XML and review the differences
    Given I create a new project named "E.130.700" by clicking on "New Project" in the menu bar, selecting "Practice / Just for fun" from the dropdown, choosing file "fixtures/cdisc_files/E130_base.xml", and clicking the "Create Project" button
    And I click on the link labeled "Manage"
    When I click on the button labeled "Enable a module"
    And I click on the button labeled "Enable" in the row labeled "Project XML Sync - v1.0.0"
    Then I should see "Project XML Sync - v1.0.0"

    When I click on the link labeled "Project XML Sync"
    And I upload a "xml" format file located at "fixtures/cdisc_files/E130_amended.xml", by clicking the button near "Select the Project XML file" to browse for the file, and clicking the button labeled "Upload and compare" to upload the file
    Then I should see "Uploaded E130_amended.xml"
    And I should see "Differences"

    # Every component that differs is listed
    And I should see "Project settings"
    And I should see "Events"
    And I should see "Fields (applied to Draft Mode)"
    And I should see "Instrument-event mapping"
    And I should see "Repeating instruments & events"
    And I should see "Data quality rules"
    And I should see "Data access groups"
    And I should see "User roles"
    And I should see "Alerts & notifications"
    And I should see "Reports"

    # Individual items
    And I should see "event_4_arm_1"
    And I should see "new_field"
    And I should see "ae_term"
    And I should see "Patient name (amended)"
    And I should see "AE grade missing"
    And I should see "DAG3"
    And I should see "New AE alert"
    And I should see "AE listing"

    # Comparing does not change the project
    When I click on the link labeled "Designer"
    Then I should NOT see "Adverse Events"
    When I click on the link labeled "Data Quality"
    Then I should NOT see "AE grade missing"

    # The XML is kept: re-opening the page shows the same comparison
    When I click on the link labeled "Project XML Sync"
    Then I should see "Comparing with E130_amended.xml"
    And I should see "new_field"

    # Disable external module in Control Center
    When I click on the link labeled "Control Center"
    And I click on the link labeled "Manage"
    And I click on the button labeled "Disable"
    Then I should see "Disable module?"
    When I click on the button labeled "Disable module"
    Then I should NOT see "Project XML Sync - v1.0.0"
    And I logout

    # Verify no exceptions are thrown in the system
    Given I open Email
    Then I should NOT see an email with subject "REDCap External Module Hook Exception - project_xml_sync"

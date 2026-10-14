<?php
/**
 * Presentation-only numbering for KRA entry labels.
 * These values are never used as database keys or scoring inputs.
 */

function kraNumberingConfig(): array {
    static $map = null;
    if ($map !== null) return $map;

    $map = [
        'kra1_a_set' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Faculty Performance Evaluation by Students and Supervisor', 'item_no'=>1, 'sub_label'=>'Student Evaluation (60%)'],
        'kra1_a_sef' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Faculty Performance Evaluation by Students and Supervisor', 'item_no'=>2, 'sub_label'=>"Supervisor's Evaluation (40%)"],
        'kra1_b_textbook_sole' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>1, 'sub_label'=>'Sole authorship'],
        'kra1_b_chapter_sole' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>1, 'sub_label'=>'Sole authorship'],
        'kra1_b_manual_sole' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>1, 'sub_label'=>'Sole authorship'],
        'kra1_b_textbook_co' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>2, 'sub_label'=>'Co-authorship'],
        'kra1_b_chapter_co' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>2, 'sub_label'=>'Co-authorship'],
        'kra1_b_manual_co' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>2, 'sub_label'=>'Co-authorship'],
        'kra1_b_multimedia' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>1, 'sub_label'=>'Sole authorship'],
        'kra1_b_testing' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Instructional Materials (IM) Development', 'item_no'=>1, 'sub_label'=>'Sole authorship'],
        'kra1_b_program_lead' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Academic programs developed/revised and implemented', 'item_no'=>1, 'sub_label'=>'New Academic Degree Program Developed'],
        'kra1_b_program_contrib' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Academic programs developed/revised and implemented', 'item_no'=>1, 'sub_label'=>'New Academic Degree Program Developed'],
        'kra1_c_adviser_special' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>1, 'sub_label'=>'Adviser'],
        'kra1_c_adviser_undergrad' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>1, 'sub_label'=>'Adviser'],
        'kra1_c_adviser_masters' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>1, 'sub_label'=>'Adviser'],
        'kra1_c_adviser_doctoral' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>1, 'sub_label'=>'Adviser'],
        'kra1_c_panel_special' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>2, 'sub_label'=>'Panel'],
        'kra1_c_panel_undergrad' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>2, 'sub_label'=>'Panel'],
        'kra1_c_panel_masters' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>2, 'sub_label'=>'Panel'],
        'kra1_c_panel_doctoral' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>"For every service rendered to students/group of students as adviser and panel member in doctoral dissertation and master's and undergraduate thesis", 'item_no'=>2, 'sub_label'=>'Panel'],
        'kra1_c_mentor_competition' => ['criterion'=>'C', 'group_no'=>2, 'group_title'=>'Mentorship Services', 'item_no'=>1, 'sub_label'=>'Mentor'],

        'kra2_a_book_sole' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>1, 'sub_label'=>'For Sole Authorship'],
        'kra2_a_monograph_sole' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>1, 'sub_label'=>'For Sole Authorship'],
        'kra2_a_journal_indexed_sole' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>1, 'sub_label'=>'For Sole Authorship'],
        'kra2_a_book_chapter_sole' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>1, 'sub_label'=>'For Sole Authorship'],
        'kra2_a_scholarly_other' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>1, 'sub_label'=>'For Sole Authorship'],
        'kra2_a_book_co' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>2, 'sub_label'=>'For Co-authorship'],
        'kra2_a_monograph_co' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>2, 'sub_label'=>'For Co-authorship'],
        'kra2_a_journal_indexed_co' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>2, 'sub_label'=>'For Co-authorship'],
        'kra2_a_book_chapter_co' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Scholarly Researches Published', 'item_no'=>2, 'sub_label'=>'For Co-authorship'],
        'kra2_a_policy_lead' => ['criterion'=>'A', 'group_no'=>2, 'group_title'=>'For every research output translated into project, policy or product', 'item_no'=>1, 'sub_label'=>'Sole Researcher'],
        'kra2_a_policy_contrib' => ['criterion'=>'A', 'group_no'=>2, 'group_title'=>'For every research output translated into project, policy or product', 'item_no'=>2, 'sub_label'=>'Contributor'],
        'kra2_a_citation_local' => ['criterion'=>'A', 'group_no'=>3, 'group_title'=>'Research publication cited', 'item_no'=>null, 'sub_label'=>'Research publication cited'],
        'kra2_a_citation_intl' => ['criterion'=>'A', 'group_no'=>3, 'group_title'=>'Research publication cited', 'item_no'=>null, 'sub_label'=>'Research publication cited'],
        'kra2_b_patent_acceptance' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>1, 'sub_label'=>'Patentable Inventions'],
        'kra2_b_patent_publication' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>1, 'sub_label'=>'Patentable Inventions'],
        'kra2_b_patent_grant' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>1, 'sub_label'=>'Patentable Inventions'],
        'kra2_b_commercialized_local' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>2, 'sub_label'=>'Commercialized Patented Product'],
        'kra2_b_commercialized_intl' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>2, 'sub_label'=>'Commercialized Patented Product'],
        'kra2_b_utility_model' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>3, 'sub_label'=>'Utility Model and Industrial Design'],
        'kra2_b_industrial_design' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Patented Inventions and Innovation', 'item_no'=>3, 'sub_label'=>'Utility Model and Industrial Design'],
        'kra2_b_software_new_sole' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Non-patentable Inventions and Innovations', 'item_no'=>1, 'sub_label'=>'Copyrighted and utilized software products'],
        'kra2_b_software_new_co' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Non-patentable Inventions and Innovations', 'item_no'=>1, 'sub_label'=>'Copyrighted and utilized software products'],
        'kra2_b_software_updated_sole' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Non-patentable Inventions and Innovations', 'item_no'=>1, 'sub_label'=>'Copyrighted and utilized software products'],
        'kra2_b_software_updated_co' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Non-patentable Inventions and Innovations', 'item_no'=>1, 'sub_label'=>'Copyrighted and utilized software products'],
        'kra2_b_plant_variety_sole' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Non-patentable Inventions and Innovations', 'item_no'=>2, 'sub_label'=>'New Plant Variety, Animal Breed, etc.'],
        'kra2_b_plant_variety_co' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Non-patentable Inventions and Innovations', 'item_no'=>2, 'sub_label'=>'New Plant Variety, Animal Breed, etc.'],
        'kra2_c_performance_own' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'Creative Works Performed, Exhibited, Designed and Published', 'item_no'=>null, 'sub_label'=>'Performing Arts'],
        'kra2_c_performance_others' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'Creative Works Performed, Exhibited, Designed and Published', 'item_no'=>null, 'sub_label'=>'Performing Arts'],
        'kra2_c_exhibition' => ['criterion'=>'C', 'group_no'=>2, 'group_title'=>'Visual Arts', 'item_no'=>null, 'sub_label'=>'Visual Arts'],
        'kra2_c_juried_design' => ['criterion'=>'C', 'group_no'=>2, 'group_title'=>'Visual Arts', 'item_no'=>null, 'sub_label'=>'Visual Arts'],
        'kra2_c_novel' => ['criterion'=>'C', 'group_no'=>3, 'group_title'=>'Literary Arts', 'item_no'=>null, 'sub_label'=>'Literary Arts'],
        'kra2_c_short_story' => ['criterion'=>'C', 'group_no'=>3, 'group_title'=>'Literary Arts', 'item_no'=>null, 'sub_label'=>'Literary Arts'],
        'kra2_c_essay' => ['criterion'=>'C', 'group_no'=>3, 'group_title'=>'Literary Arts', 'item_no'=>null, 'sub_label'=>'Literary Arts'],
        'kra2_c_poetry' => ['criterion'=>'C', 'group_no'=>3, 'group_title'=>'Literary Arts', 'item_no'=>null, 'sub_label'=>'Literary Arts'],

        'kra3_a_moa' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Service to the Institution', 'item_no'=>null, 'sub_label'=>'For Every Successful Linkages/Networking/Partnership Activity'],
        'kra3_a_income_below6m' => ['criterion'=>'A', 'group_no'=>2, 'group_title'=>'Total Contribution to Income Generation for the 3-Year Period', 'item_no'=>null, 'sub_label'=>'Total Contribution to Income Generation for the 3-Year Period'],
        'kra3_a_income_6to12m' => ['criterion'=>'A', 'group_no'=>2, 'group_title'=>'Total Contribution to Income Generation for the 3-Year Period', 'item_no'=>null, 'sub_label'=>'Total Contribution to Income Generation for the 3-Year Period'],
        'kra3_a_income_above12m' => ['criterion'=>'A', 'group_no'=>2, 'group_title'=>'Total Contribution to Income Generation for the 3-Year Period', 'item_no'=>null, 'sub_label'=>'Total Contribution to Income Generation for the 3-Year Period'],
        'kra3_b_accreditation_local' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>1, 'sub_label'=>'For Services in Accreditation, Evaluation, Assessment Works, and other related Educational Quality Assurance Activities'],
        'kra3_b_accreditation_intl' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>1, 'sub_label'=>'For Services in Accreditation, Evaluation, Assessment Works, and other related Educational Quality Assurance Activities'],
        'kra3_b_judge_research' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>2, 'sub_label'=>'For Services as Judge/Examiner for local/international research awards and academic competitions'],
        'kra3_b_judge_competition' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>2, 'sub_label'=>'For Services as Judge/Examiner for local/international research awards and academic competitions'],
        'kra3_b_consultant_local' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>3, 'sub_label'=>'For services rendered as a short-term consultant/expert in an activity of an educational, technological, professional, scientific or cultural nature (foreign or local) sponsored by a private organization or government'],
        'kra3_b_consultant_intl' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>3, 'sub_label'=>'For services rendered as a short-term consultant/expert in an activity of an educational, technological, professional, scientific or cultural nature (foreign or local) sponsored by a private organization or government'],
        'kra3_b_column_occasional' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>4, 'sub_label'=>'For services through media as:'],
        'kra3_b_column_regular' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>4, 'sub_label'=>'For services through media as:'],
        'kra3_b_tv_radio_host' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>4, 'sub_label'=>'For services through media as:'],
        'kra3_b_technical_guest' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>4, 'sub_label'=>'For services through media as:'],
        'kra3_b_resource_local' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>5, 'sub_label'=>'For every hour of training course/seminar/workshop conducted as Resource Person/Convenor/Facilitator/Moderator/Keynote Speaker/Plenary Speaker/Panelist'],
        'kra3_b_resource_intl' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Professional/Expertise-based Services', 'item_no'=>5, 'sub_label'=>'For every hour of training course/seminar/workshop conducted as Resource Person/Convenor/Facilitator/Moderator/Keynote Speaker/Plenary Speaker/Panelist'],
        'kra3_b_outreach_head' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Institutional Social Responsibility', 'item_no'=>1, 'sub_label'=>'For every service-oriented project in the community participated in including advocacy initiatives'],
        'kra3_b_outreach_participant' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'Institutional Social Responsibility', 'item_no'=>1, 'sub_label'=>'For every service-oriented project in the community participated in including advocacy initiatives'],
        'kra3_c_satisfaction' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'Quality of Extension', 'item_no'=>null, 'sub_label'=>'Client Satisfaction Rating for Outreach and Extension Projects'],
        'kra3_d_president' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_vp' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_chancellor' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_vice_chancellor' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_campus_director' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_faculty_regent' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_office_director' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_univ_secretary' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_project_head_inst' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_committee_chair_inst' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_committee_member_inst' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_dean' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_assoc_dean' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_college_secretary' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_dept_head' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_program_chair' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_committee_chair_dept' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],
        'kra3_d_committee_member_dept' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For Administrative Designation', 'item_no'=>null, 'sub_label'=>'Administrative Designation'],

        'kra4_a_org_membership' => ['criterion'=>'A', 'group_no'=>1, 'group_title'=>'Involvement in Professional Organizations', 'item_no'=>null, 'sub_label'=>'For Current Individual Membership and Active Role/Contribution in Relevant, Recognized/Registered Professional Organization, Learned/Honor/Scientific Society'],
        'kra4_b_postmaster_cert' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Educational Qualifications (Max - 40 points)', 'item_no'=>2, 'sub_label'=>'For additional degrees'],
        'kra4_b_postdoc_cert' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Educational Qualifications (Max - 40 points)', 'item_no'=>2, 'sub_label'=>'For additional degrees'],
        'kra4_b_additional_masters' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Educational Qualifications (Max - 40 points)', 'item_no'=>2, 'sub_label'=>'For additional degrees'],
        'kra4_b_doctorate' => ['criterion'=>'B', 'group_no'=>1, 'group_title'=>'Educational Qualifications (Max - 40 points)', 'item_no'=>1, 'sub_label'=>'For doctorate degree'],
        'kra4_b_training_local' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'For every participation in conferences, seminars, workshops, industry immersion (Max = 10 points)', 'item_no'=>null, 'sub_label'=>'For every participation in conferences, seminars, workshops, industry immersion (Max = 10 points)'],
        'kra4_b_training_intl' => ['criterion'=>'B', 'group_no'=>2, 'group_title'=>'For every participation in conferences, seminars, workshops, industry immersion (Max = 10 points)', 'item_no'=>null, 'sub_label'=>'For every participation in conferences, seminars, workshops, industry immersion (Max = 10 points)'],
        'kra4_b_paper_local' => ['criterion'=>'B', 'group_no'=>3, 'group_title'=>'For every paper presentation in conferences (Max = 10 points)', 'item_no'=>null, 'sub_label'=>'For every paper presentation in conferences (Max = 10 points)'],
        'kra4_b_paper_intl' => ['criterion'=>'B', 'group_no'=>3, 'group_title'=>'For every paper presentation in conferences (Max = 10 points)', 'item_no'=>null, 'sub_label'=>'For every paper presentation in conferences (Max = 10 points)'],
        'kra4_c_award_institutional' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'For every award of distinction received in recognition of achievement in relevant areas of specialization/profession and/or assignment of the faculty concerned', 'item_no'=>null, 'sub_label'=>'Institutional'],
        'kra4_c_award_local' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'For every award of distinction received in recognition of achievement in relevant areas of specialization/profession and/or assignment of the faculty concerned', 'item_no'=>null, 'sub_label'=>'Local / Regional (City, Municipality, Province)'],
        'kra4_c_award_national' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'For every award of distinction received in recognition of achievement in relevant areas of specialization/profession and/or assignment of the faculty concerned', 'item_no'=>null, 'sub_label'=>'National'],
        'kra4_c_award_international' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'For every award of distinction received in recognition of achievement in relevant areas of specialization/profession and/or assignment of the faculty concerned', 'item_no'=>null, 'sub_label'=>'International (CONFIG GAP — pending CHED-RO confirmation)'],
        'kra4_c_award_prestigious' => ['criterion'=>'C', 'group_no'=>1, 'group_title'=>'Prestigious Award / Auto Sub-Rank Trigger (JC01 Art. IV, Trigger 2)', 'item_no'=>null, 'sub_label'=>'Prestigious Award — Auto Sub-Rank Trigger only (0 KRA IV pts; requires checker confirmation)'],
        'kra4_d_academic_president' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For every year of full-time academic service in an institution of higher learning as:', 'item_no'=>null, 'sub_label'=>'For every year of full-time academic service in an institution of higher learning as:'],
        'kra4_d_academic_vp_dean' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For every year of full-time academic service in an institution of higher learning as:', 'item_no'=>null, 'sub_label'=>'For every year of full-time academic service in an institution of higher learning as:'],
        'kra4_d_academic_dept_head' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For every year of full-time academic service in an institution of higher learning as:', 'item_no'=>null, 'sub_label'=>'For every year of full-time academic service in an institution of higher learning as:'],
        'kra4_d_academic_faculty' => ['criterion'=>'D', 'group_no'=>1, 'group_title'=>'For every year of full-time academic service in an institution of higher learning as:', 'item_no'=>null, 'sub_label'=>'For every year of full-time academic service in an institution of higher learning as:'],
        'kra4_d_industry_managerial' => ['criterion'=>'D', 'group_no'=>2, 'group_title'=>'For every year of industry experience (non-academic organization) in:', 'item_no'=>null, 'sub_label'=>'For every year of industry experience (non-academic organization) in:'],
        'kra4_d_industry_technical' => ['criterion'=>'D', 'group_no'=>2, 'group_title'=>'For every year of industry experience (non-academic organization) in:', 'item_no'=>null, 'sub_label'=>'For every year of industry experience (non-academic organization) in:'],
        'kra4_d_industry_support' => ['criterion'=>'D', 'group_no'=>2, 'group_title'=>'For every year of industry experience (non-academic organization) in:', 'item_no'=>null, 'sub_label'=>'For every year of industry experience (non-academic organization) in:'],
    ];

    return $map;
}

function kraNumberingEntryByKey(string $key): ?array {
    $map = kraNumberingConfig();
    return $map[$key] ?? null;
}

function kraNumberingPrefix(array $entry): string {
    $group = (int)($entry['group_no'] ?? 0);
    $item = $entry['item_no'] ?? null;
    if ($group <= 0) return '';
    return $item === null ? $group . '.' : $group . '.' . (int)$item;
}

function kraNumberingDisplayLabel(array $entry): string {
    return trim(kraNumberingPrefix($entry) . ' ' . kraNumberingSubLabel($entry));
}

function kraNumberingSubLabel(array $entry): string {
    return (string)($entry['sub_label'] ?? $entry['label'] ?? '');
}

function kraCleanEntryDisplayTitle(string $title): string {
    $title = html_entity_decode(trim($title), ENT_QUOTES, 'UTF-8');
    $title = str_replace(["\xE2\x80\x93", "\xE2\x80\x94"], '-', $title);
    $title = preg_replace('/^Criterion\s+[A-Z](?:\s*\([^)]*\))?\s*[-.:]\s*/i', '', $title);
    $title = preg_replace('/\s*\(\s*\d+(?:\.\d+)?\s*(?:x\s*contrib%)?\s*pts?\.?\s*\)\s*$/i', '', $title);
    $title = preg_replace('/\s*\(\s*\d+(?:\.\d+)?\s*pts?\s*max\s*\)\s*$/i', '', $title);
    $title = preg_replace('/\s+/', ' ', $title);
    return trim($title);
}

function kraCriterionDisplayHeader(string $criterionLabel, ?array $numbering = null): string {
    $letter = '';
    if (preg_match('/Criterion\s+([A-Z])/i', $criterionLabel, $m)) {
        $letter = strtoupper($m[1]);
    } elseif ($numbering && !empty($numbering['criterion'])) {
        $letter = strtoupper($numbering['criterion']);
    }

    $titles = [
        'A' => [
            'Instruction' => 'Teaching Effectiveness',
            'Research' => 'Research Outputs Published',
            'Extension' => 'Service to the Institution',
            'Professional Development' => 'Involvement in Professional Organization',
        ],
        'B' => [
            'Instruction' => 'Curriculum and Instructional Materials Developed',
            'Research' => 'Inventions',
            'Extension' => 'Service to the Community',
            'Professional Development' => 'Continuing Development',
        ],
        'C' => [
            'Instruction' => 'Special Projects, Capstone Projects, Thesis, Dissertation and Mentorship Services',
            'Research' => 'Creative Works',
            'Extension' => 'Quality of Extension Services',
            'Professional Development' => 'Awards and Recognition',
        ],
        'D' => [
            'Extension' => 'Bonus Criterion',
            'Professional Development' => 'Bonus Indicators for Newly Hired Faculty',
        ],
    ];

    $category = null;
    foreach (['Instruction', 'Research', 'Extension', 'Professional Development'] as $cat) {
        if (str_contains($criterionLabel, $cat)) {
            $category = $cat;
            break;
        }
    }
    if (!$category && $numbering) {
        $key = array_search($numbering, kraNumberingConfig(), true);
        if (is_string($key)) {
            $category = str_starts_with($key, 'kra1_') ? 'Instruction'
                : (str_starts_with($key, 'kra2_') ? 'Research'
                : (str_starts_with($key, 'kra3_') ? 'Extension'
                : (str_starts_with($key, 'kra4_') ? 'Professional Development' : null)));
        }
    }

    if ($letter && $category && isset($titles[$letter][$category])) {
        return 'Criterion ' . $letter . '. ' . $titles[$letter][$category];
    }

    return preg_replace('/\s*\(.*$/', '', str_replace(' - ', '. ', $criterionLabel));
}

function kraNumberingFindByStoredType(string $category, string $type, array $parts = []): ?array {
    $map = kraNumberingConfig();
    $type = trim($type);

    if ($category === 'Instruction') {
        if ($type === 'A-set-sem') return $map['kra1_a_set'] ?? null;
        if ($type === 'A-sef-sem') return $map['kra1_a_sef'] ?? null;
        if ($type === 'A-set-sef' || $type === 'A-set-sef-sem') return null;
        if (str_starts_with($type, 'B|')) {
            $label = strtolower($parts[1] ?? $type);
            $isCo = str_contains($label, 'co-author');
            $isProgram = str_contains($label, 'program');
            if ($isProgram) return $map[$isCo || str_contains($label, 'contributor') ? 'kra1_b_program_contrib' : 'kra1_b_program_lead'] ?? null;
            if (str_contains($label, 'chapter')) return $map[$isCo ? 'kra1_b_chapter_co' : 'kra1_b_chapter_sole'] ?? null;
            if (str_contains($label, 'manual') || str_contains($label, 'module')) return $map[$isCo ? 'kra1_b_manual_co' : 'kra1_b_manual_sole'] ?? null;
            if (str_contains($label, 'testing')) return $map['kra1_b_testing'] ?? null;
            if (str_contains($label, 'multimedia')) return $map['kra1_b_multimedia'] ?? null;
            return $map[$isCo ? 'kra1_b_textbook_co' : 'kra1_b_textbook_sole'] ?? null;
        }
        if (str_starts_with($type, 'C|')) {
            $label = strtolower($parts[1] ?? $type);
            if (str_contains($label, 'mentor')) return $map['kra1_c_mentor_competition'] ?? null;
            if (str_contains($label, 'panel')) return $map['kra1_c_panel_undergrad'] ?? null;
            return $map['kra1_c_adviser_undergrad'] ?? null;
        }
        if ($type === 'C-mentor') return $map['kra1_c_mentor_competition'] ?? null;
    }

    if ($category === 'Research') {
        $label = strtolower(preg_replace('/\s*\(\d+(?:\.\d+)?\s*pts?\)\s*$/i', '', $type));
        if (str_contains($label, 'citation')) return $map['kra2_a_citation_local'] ?? null;
        if (str_contains($label, 'policy') || str_contains($label, 'product') || str_contains($label, 'project')) {
            return str_contains($label, 'contrib') ? ($map['kra2_a_policy_contrib'] ?? null) : ($map['kra2_a_policy_lead'] ?? null);
        }
        if (str_contains($label, 'commercialized')) return $map['kra2_b_commercialized_local'] ?? null;
        if (str_contains($label, 'utility') || str_contains($label, 'industrial design')) return $map['kra2_b_utility_model'] ?? null;
        if (str_contains($label, 'patent')) return $map['kra2_b_patent_grant'] ?? null;
        if (str_contains($label, 'software')) return $map['kra2_b_software_new_sole'] ?? null;
        if (str_contains($label, 'plant') || str_contains($label, 'animal') || str_contains($label, 'microbial')) return $map['kra2_b_plant_variety_sole'] ?? null;
        if (str_contains($label, 'performance')) return $map['kra2_c_performance_own'] ?? null;
        if (str_contains($label, 'exhibition') || str_contains($label, 'design') || str_contains($label, 'visual')) return $map['kra2_c_exhibition'] ?? null;
        if (str_contains($label, 'novel') || str_contains($label, 'short story') || str_contains($label, 'essay') || str_contains($label, 'poetry') || str_contains($label, 'literary')) return $map['kra2_c_novel'] ?? null;
        if (str_contains($label, 'co-author')) return $map['kra2_a_book_co'] ?? null;
        if (str_contains($label, 'sole') || str_contains($label, 'book') || str_contains($label, 'monograph') || str_contains($label, 'journal') || str_contains($label, 'scholarly')) return $map['kra2_a_book_sole'] ?? null;
    }

    if ($category === 'Extension') {
        $keyBySubtype = [
            'moa-linkage'=>'kra3_a_moa', 'income'=>'kra3_a_income_below6m',
            'accredit-local'=>'kra3_b_accreditation_local', 'accredit-intl'=>'kra3_b_accreditation_intl',
            'judge-research'=>'kra3_b_judge_research', 'judge-other'=>'kra3_b_judge_competition',
            'consultant-local'=>'kra3_b_consultant_local', 'consultant-intl'=>'kra3_b_consultant_intl',
            'media-column-occasional'=>'kra3_b_column_occasional', 'media-column-regular'=>'kra3_b_column_regular',
            'media-tv-radio-host'=>'kra3_b_tv_radio_host', 'media-guest'=>'kra3_b_technical_guest',
            'resource-speaker-local'=>'kra3_b_resource_local', 'resource-speaker-intl'=>'kra3_b_resource_intl',
            'outreach-isr-lead'=>'kra3_b_outreach_head', 'outreach-isr-member'=>'kra3_b_outreach_participant',
            'csr-satisfaction'=>'kra3_c_satisfaction',
        ];
        if (isset($keyBySubtype[$type])) return $map[$keyBySubtype[$type]] ?? null;
        if (in_array($type, ['president','vice-president','chancellor','vice-chancellor','campus director','office director','dean','associate dean','dept head','program chair','committee chair','committee member','coordinator'], true)) {
            return $map['kra3_d_president'] ?? null;
        }
    }

    if ($category === 'Professional Development') {
        if ($type === 'A-org') return $map['kra4_a_org_membership'] ?? null;
        if ($type === 'B-degree') return ((float)($parts[2] ?? 0) >= 40) ? ($map['kra4_b_doctorate'] ?? null) : ($map['kra4_b_additional_masters'] ?? null);
        if ($type === 'B-training') return $map['kra4_b_training_local'] ?? null;
        if ($type === 'B-paper') return $map['kra4_b_paper_local'] ?? null;
        if ($type === 'C-award') return $map['kra4_c_award_institutional'] ?? null;
        if (str_starts_with($type, 'D-prior-academic')) return $map['kra4_d_academic_faculty'] ?? null;
        if (str_starts_with($type, 'D-prior-industry')) return $map['kra4_d_industry_support'] ?? null;
    }

    return null;
}
